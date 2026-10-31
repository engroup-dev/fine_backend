<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreSupplierRequest;
use App\Http\Resources\v1\SupplierResource;
use App\Models\Entity;
use App\Models\Supplier;
use App\Rules\LeafAccount;
use App\Services\CoaLinkService;
use App\Services\EntityService;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Supplier::with(['operatingUnit', 'account', 'entity']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return SupplierResource::collection($query->latest()->get());
    }

    public function store(StoreSupplierRequest $request, CoaLinkService $coaLinkService, SupplierService $supplierService): JsonResponse
    {
        // The duplicate check reads across the unit scope, so the body's unit
        // must be one the caller may work in.
        if (($forbidden = $this->unitForbidden($request, (string) $request->input('operating_unit_id'))) !== null) {
            return $forbidden;
        }

        // W5-07: refuse a likely duplicate (same normalized name, tax number
        // or linked entity in the unit) unless the caller confirms with force.
        // A linked entity's own tax number counts when the request has none.
        if (! $request->boolean('force')) {
            $taxNumber = $request->input('tax_number');
            if (SupplierService::normalizeTaxNumber($taxNumber) === null && $request->filled('entity_id')) {
                $taxNumber = Entity::query()->whereKey($request->input('entity_id'))->value('tax_number');
            }

            $duplicates = $supplierService->findDuplicates(
                (string) $request->input('operating_unit_id'),
                $request->input('name'),
                $taxNumber,
                $request->input('entity_id'),
            );

            if ($duplicates !== []) {
                return $this->duplicateResponse($duplicates);
            }
        }

        $supplier = DB::transaction(function () use ($request, $coaLinkService, $supplierService) {
            $accountId = $coaLinkService->resolveOrProvisionAccount(
                $request->only(['coa_action', 'account_id', 'new_account']),
                $request->user()?->company_id
            );

            $entity = $supplierService->resolveEntityForNewSupplier(
                entityId: $request->input('entity_id'),
                name: (string) $request->input('name'),
                taxNumber: $request->input('tax_number'),
                contact: $request->input('contact'),
                address: $request->input('address'),
                operatingUnitId: (string) $request->input('operating_unit_id'),
            );

            $data = $request->only('operating_unit_id', 'name', 'contact', 'default_currency', 'address');
            $data['account_id'] = $accountId;
            $data['entity_id'] = $entity->id;

            return Supplier::create($data)->load(['operatingUnit', 'account', 'entity']);
        });

        return (new SupplierResource($supplier))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): SupplierResource
    {
        $supplier = Supplier::with(['operatingUnit', 'account', 'entity'])->findOrFail($id);

        return new SupplierResource($supplier);
    }

    public function update(Request $request, string $id, CoaLinkService $coaLinkService, SupplierService $supplierService, EntityService $entityService): SupplierResource|JsonResponse
    {
        $supplier = Supplier::with('entity')->findOrFail($id);

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'name' => 'sometimes|required|string|max:255',
            'contact' => 'nullable|string|max:255',
            'default_currency' => 'sometimes|string|size:3',
            'address' => 'nullable|string',
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true, unchangedValue: $supplier->account_id)],
            'coa_action' => 'nullable|string|in:create_new,link_existing,none',
            'new_account' => 'nullable|array',
            'new_account.parent_account_id' => 'required_if:coa_action,create_new|nullable|uuid|exists:accounts,id',
            'new_account.account_code' => 'required_if:coa_action,create_new|nullable|string|max:50|unique:accounts,account_code',
            'new_account.name' => 'required_if:coa_action,create_new|nullable|string|max:255',
            'new_account.currency' => 'nullable|string|size:3',
            // W5-07: the tax number lives on the supplier's entity; entity_id
            // relinks the supplier to another party (a client's entity).
            'tax_number' => 'nullable|string|max:100',
            'entity_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('entities', 'id')->whereNull('deleted_at')],
            'force' => 'sometimes|boolean',
            'record_version' => 'sometimes|integer',
        ]);

        $unitChanged = $request->has('operating_unit_id') && $request->input('operating_unit_id') !== $supplier->operating_unit_id;
        if ($unitChanged && ($forbidden = $this->unitForbidden($request, (string) $request->input('operating_unit_id'))) !== null) {
            return $forbidden;
        }

        $entityChanged = $request->filled('entity_id') && $request->input('entity_id') !== $supplier->entity_id;
        $entityAfter = $entityChanged ? Entity::query()->find($request->input('entity_id')) : $supplier->entity;

        // The tax number the supplier ends up with: the request's, or its
        // entity's when the request leaves it empty, unless an empty value
        // clears it on the supplier's own private entity (no other role, no
        // other unit, no other supplier).
        $requestTax = SupplierService::normalizeTaxNumber($request->input('tax_number')) !== null ? $request->input('tax_number') : null;
        $clearsTax = $request->has('tax_number') && $requestTax === null && ! $entityChanged
            && $entityAfter !== null && ! SupplierService::entityIsShared($entityAfter, $supplier);
        $taxAfter = $requestTax ?? ($clearsTax ? null : $entityAfter?->tax_number);

        $nameChanged = $request->has('name')
            && SupplierService::normalizeName($request->input('name')) !== SupplierService::normalizeName($supplier->name);
        $taxChanged = SupplierService::normalizeTaxNumber($taxAfter) !== SupplierService::normalizeTaxNumber($supplier->entity?->tax_number);

        if (! $request->boolean('force') && ($nameChanged || $taxChanged || $unitChanged || $entityChanged)) {
            $duplicates = $supplierService->findDuplicates(
                (string) $request->input('operating_unit_id', $supplier->operating_unit_id),
                $request->input('name', $supplier->name),
                $taxAfter,
                $entityChanged ? $entityAfter?->id : null,
                $supplier->id,
            );

            if ($duplicates !== []) {
                return $this->duplicateResponse($duplicates);
            }
        }

        $supplier = DB::transaction(function () use ($request, $supplier, $coaLinkService, $supplierService, $nameChanged, $unitChanged, $entityChanged, $entityService) {
            $accountId = $supplier->account_id;
            if ($request->has('coa_action') || $request->has('account_id')) {
                $accountId = $coaLinkService->resolveOrProvisionAccount(
                    $request->only(['coa_action', 'account_id', 'new_account']),
                    $request->user()?->company_id
                );
            }

            $oldUnitId = $supplier->operating_unit_id;
            $oldEntityId = $supplier->entity_id;

            $data = $request->only('operating_unit_id', 'name', 'contact', 'default_currency', 'address', 'record_version');
            $data['account_id'] = $accountId;
            if ($entityChanged) {
                $data['entity_id'] = $request->input('entity_id');
            }

            $supplier->update($data);

            // The entity is a vendor of the unit the supplier now belongs to,
            // and stops being one where no live supplier of it remains.
            if ($entityChanged || $unitChanged) {
                $entity = $entityChanged
                    ? Entity::query()->lockForUpdate()->findOrFail($supplier->entity_id)
                    : $supplierService->ensureEntity($supplier);
                $entityService->ensureEntityRole($entity, EntityRoleType::Vendor, $supplier->operating_unit_id);
                $supplierService->releaseVendorRole($oldEntityId, $oldUnitId, $supplier->id);
            }

            if ($request->has('tax_number') || $nameChanged) {
                $entity = $supplierService->ensureEntity($supplier);
                $entityData = [];

                // A shared entity (also a client or an employee, a vendor of
                // another unit, or behind another supplier), or one just
                // linked, keeps its own tax number: a missing one is filled
                // in, a different one is refused, an empty one changes
                // nothing. A shared entity keeps its name too. The supplier's
                // own private entity follows the supplier.
                $shared = SupplierService::entityIsShared($entity, $supplier);

                if ($request->has('tax_number')) {
                    $taxNumber = $request->input('tax_number') ?: null;

                    if (! $shared && ! $entityChanged) {
                        $entityData['tax_number'] = $taxNumber;
                    } else {
                        $supplierService->assertTaxNumberFits($entity, $taxNumber);
                        if (SupplierService::normalizeTaxNumber($taxNumber) !== null && SupplierService::normalizeTaxNumber($entity->tax_number) === null) {
                            $entityData['tax_number'] = $taxNumber;
                        }
                    }
                }

                if ($nameChanged && ! $shared && ! $entityChanged) {
                    $entityData['name'] = $supplier->name;
                }

                if ($entityData !== []) {
                    $entity->update($entityData);
                }
            }

            return $supplier->load(['operatingUnit', 'account', 'entity']);
        });

        return new SupplierResource($supplier);
    }

    /**
     * W5-07: merges this supplier into target_id. The target survives; this
     * supplier's orders and cheques move to it and it is soft-deleted.
     */
    public function merge(Request $request, string $id, SupplierService $supplierService): JsonResponse
    {
        $request->validate([
            'target_id' => 'required|uuid',
        ]);

        if ($request->input('target_id') === $id) {
            throw ValidationException::withMessages([
                'target_id' => 'Choose a different supplier to merge into.',
            ]);
        }

        $result = $supplierService->merge($id, (string) $request->input('target_id'));

        return response()->json([
            'data' => (new SupplierResource($result['supplier']))->resolve($request),
            'moved' => $result['moved'],
            'merged_supplier_id' => $id,
        ]);
    }

    /**
     * @param  list<array{id: string, name: string, tax_number: string|null, match: string}>  $duplicates
     */
    private function duplicateResponse(array $duplicates): JsonResponse
    {
        return response()->json([
            'message' => "A supplier like this already exists ({$duplicates[0]['name']}). Merge them, or send force to save anyway.",
            'code' => 'SUPPLIER_DUPLICATE',
            'duplicates' => $duplicates,
        ], 422);
    }

    /**
     * The caller may work in the unit: a role scoped to it, or a company-wide
     * role. Otherwise a 403 with SUPPLIER_UNIT_FORBIDDEN.
     */
    private function unitForbidden(Request $request, string $operatingUnitId): ?JsonResponse
    {
        $allowed = $request->user()?->roles()
            ->where(function ($query) use ($operatingUnitId): void {
                $query->where('user_roles.operating_unit_id', $operatingUnitId)
                    ->orWhereNull('user_roles.operating_unit_id');
            })
            ->exists() ?? false;

        return $allowed ? null : response()->json([
            'message' => 'You do not have access to this operating unit.',
            'code' => 'SUPPLIER_UNIT_FORBIDDEN',
        ], 403);
    }

    public function destroy(string $id, SupplierService $supplierService): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        // W5-07: the entity stops listing as a vendor of the unit once no
        // live supplier of it remains there.
        DB::transaction(function () use ($supplier, $supplierService): void {
            $supplier->delete();
            $supplierService->releaseVendorRole($supplier->entity_id, $supplier->operating_unit_id, $supplier->id);
        });

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}
