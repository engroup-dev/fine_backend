<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ClientStatus;
use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreClientRequest;
use App\Http\Resources\v1\ClientResource;
use App\Models\Client;
use App\Models\ClientCreditLimitChange;
use App\Models\ClientGroup;
use App\Models\Entity;
use App\Models\Scopes\OperatingUnitScope;
use App\Rules\LeafAccount;
use App\Services\ClientSummaryService;
use App\Services\CoaLinkService;
use App\Services\EntityService;
use App\Support\CurrentUnitContext;
use App\Support\ReceivableAccounts;
use App\Support\ReferenceGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClientController extends Controller
{
    /**
     * Display a listing of clients.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Client::with(['entity.primaryContact', 'operatingUnit', 'account', 'clientGroup']);

        // Unit drill-down is only honoured for company-wide roles; a unit
        // caller's listing stays inside their ambient unit scope.
        if ($request->has('operating_unit_id') && $request->user()->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('client_group_id')) {
            $query->where('client_group_id', $request->query('client_group_id'));
        }

        return ClientResource::collection($query->latest()->get());
    }

    /**
     * Store a newly created client.
     */
    public function store(StoreClientRequest $request, EntityService $entityService, CoaLinkService $coaLinkService, CurrentUnitContext $unitContext): JsonResponse
    {
        $operatingUnitId = $request->operating_unit_id ?? $unitContext->getUnitId();

        if ($operatingUnitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before registering a client.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $client = DB::transaction(function () use ($request, $entityService, $coaLinkService, $operatingUnitId) {
            $entityId = $request->entity_id;

            if (! $entityId) {
                $name = $request->input('name', 'Client Company');

                $entity = $entityService->createEntityForDomainModel(
                    roleType: EntityRoleType::Client,
                    attributes: [
                        'name' => $name,
                        'entity_type' => $request->input('entity_type', EntityType::Organization),
                        'tax_number' => $request->tax_number,
                    ],
                    operatingUnitId: $operatingUnitId
                );
                $entityId = $entity->id;
            } else {
                $entity = Entity::findOrFail($entityId);
                $entityService->ensureEntityRole($entity, EntityRoleType::Client, $operatingUnitId);
            }

            if ($request->filled('city') || $request->filled('address') || $request->filled('phone')) {
                $entity = Entity::find($entityId);
                if ($entity) {
                    $entity->contacts()->updateOrCreate(
                        ['is_primary' => true],
                        [
                            'phone' => $request->input('phone'),
                            'city' => $request->input('city'),
                            'address' => $request->input('address'),
                            'country' => 'LY',
                        ]
                    );
                }
            }

            // Every client gets its own receivable account under 122 unless
            // the caller chose otherwise — sales on credit post to it.
            $accountId = $coaLinkService->resolveOrProvisionAccount(
                [
                    ...$request->only(['coa_action', 'account_id', 'new_account']),
                    'coa_action' => $request->input('coa_action') ?? ($request->filled('account_id') ? null : 'auto'),
                    'auto_parent_code' => ReceivableAccounts::ROOT_CODE,
                    'auto_name' => 'عميل - '.Entity::find($entityId)?->name,
                ],
                $request->user()?->company_id
            );

            // W5-06: whatever the caller leaves out comes from the client's group.
            $group = $request->filled('client_group_id')
                ? $this->lockAssignableGroup((string) $request->input('client_group_id'))
                : null;

            $client = Client::create([
                'entity_id' => $entityId,
                'operating_unit_id' => $operatingUnitId,
                'client_group_id' => $group?->id,
                'credit_limit' => $request->input('credit_limit') ?? $group?->default_credit_limit ?? 0,
                'payment_terms_days' => $request->input('payment_terms_days') ?? $group?->default_payment_terms_days ?? 30,
                'account_id' => $accountId,
                'status' => $request->input('status', 'active'),
            ]);

            return $client->load(['entity.primaryContact', 'operatingUnit', 'account', 'clientGroup']);
        });

        return (new ClientResource($client))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified client.
     */
    public function show(string $id): ClientResource
    {
        $client = Client::with(['entity.primaryContact', 'operatingUnit', 'account', 'clientGroup'])->findOrFail($id);

        return new ClientResource($client);
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, string $id, CoaLinkService $coaLinkService): ClientResource
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'entity_type' => ['sometimes', 'required', Rule::enum(EntityType::class)],
            'tax_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'credit_limit' => 'sometimes|numeric|min:0',
            'credit_limit_reason' => 'nullable|string|max:500',
            'payment_terms_days' => 'sometimes|integer|min:0',
            // Moving a client to another group keeps its own limit and terms.
            'client_group_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_groups', 'id')->whereNull('deleted_at')],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id', new LeafAccount(
                requireActive: true,
                unchangedValue: Client::query()->whereKey($id)->value('account_id'),
            )],
            'coa_action' => 'nullable|string|in:create_new,link_existing,none',
            'new_account' => 'nullable|array',
            'new_account.parent_account_id' => 'required_if:coa_action,create_new|nullable|uuid|exists:accounts,id',
            'new_account.account_code' => 'required_if:coa_action,create_new|nullable|string|max:50|unique:accounts,account_code',
            'new_account.name' => 'required_if:coa_action,create_new|nullable|string|max:255',
            'new_account.currency' => 'nullable|string|size:3',
            'status' => ['sometimes', 'required', Rule::enum(ClientStatus::class)],
            'record_version' => 'required|integer',
        ]);

        $client = DB::transaction(function () use ($request, $id, $coaLinkService) {
            $client = Client::with('entity')->findOrFail($id);

            // A group being removed at the same moment cannot be assigned.
            if ($request->filled('client_group_id') && $request->input('client_group_id') !== $client->client_group_id) {
                $this->lockAssignableGroup((string) $request->input('client_group_id'));
            }

            $accountId = $client->account_id;
            if ($request->has('coa_action') || $request->has('account_id')) {
                $accountId = $coaLinkService->resolveOrProvisionAccount(
                    $request->only(['coa_action', 'account_id', 'new_account']),
                    $request->user()?->company_id
                );
            }

            $data = $request->only(
                'operating_unit_id',
                'client_group_id',
                'credit_limit',
                'payment_terms_days',
                'status',
                'record_version'
            );
            $data['account_id'] = $accountId;

            $oldLimit = round((float) $client->credit_limit, 4);

            $client->update($data);

            // Every credit limit change is logged with who made it and why.
            $newLimit = round((float) $client->credit_limit, 4);
            if (abs($newLimit - $oldLimit) > 0.00001) {
                ClientCreditLimitChange::create([
                    'client_id' => $client->id,
                    'old_limit' => $oldLimit,
                    'new_limit' => $newLimit,
                    'reason' => $request->input('credit_limit_reason'),
                    'changed_by' => $request->user()?->id,
                ]);
            }

            if ($client->entity) {
                $entityData = [];
                if ($request->has('name')) {
                    $entityData['name'] = $request->input('name');
                }
                if ($request->has('entity_type')) {
                    $entityData['entity_type'] = $request->input('entity_type');
                }
                if ($request->has('tax_number')) {
                    $entityData['tax_number'] = $request->input('tax_number');
                }
                if (! empty($entityData)) {
                    $client->entity->update($entityData);
                }

                if ($request->has('phone') || $request->has('city') || $request->has('address')) {
                    $contactData = ['country' => 'LY'];
                    if ($request->has('phone')) {
                        $contactData['phone'] = $request->input('phone');
                    }
                    if ($request->has('city')) {
                        $contactData['city'] = $request->input('city');
                    }
                    if ($request->has('address')) {
                        $contactData['address'] = $request->input('address');
                    }
                    $client->entity->contacts()->updateOrCreate(
                        ['is_primary' => true],
                        $contactData
                    );
                }
            }

            return $client->load(['entity.primaryContact', 'operatingUnit', 'account', 'clientGroup']);
        });

        return new ClientResource($client);
    }

    /**
     * W5-06: re-reads the group under a row lock inside the caller's
     * transaction, so it cannot be removed while a client joins it (the
     * removal locks the same row and counts the group's clients). The
     * validator's exists check runs before the transaction and is not enough.
     */
    private function lockAssignableGroup(string $groupId): ClientGroup
    {
        $group = ClientGroup::query()->lockForUpdate()->find($groupId);

        if ($group === null) {
            throw ValidationException::withMessages([
                'client_group_id' => 'The selected client group no longer exists.',
            ]);
        }

        return $group;
    }

    /**
     * The client card's money summary: ledger aging and overdue, the last
     * collection and the sales still open.
     */
    public function summary(string $id, ClientSummaryService $summaryService): JsonResponse
    {
        $client = Client::findOrFail($id);

        return response()->json(['data' => $summaryService->summary($client)]);
    }

    /**
     * The client's credit limit history, newest first.
     */
    public function creditLimitChanges(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        $changes = $client->creditLimitChanges()
            ->with('changedBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ClientCreditLimitChange $change): array => [
                'id' => $change->id,
                'old_limit' => (float) $change->old_limit,
                'new_limit' => (float) $change->new_limit,
                'reason' => $change->reason,
                'changed_by' => $change->changedBy ? ['id' => $change->changedBy->id, 'name' => $change->changedBy->name] : null,
                'created_at' => $change->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $changes]);
    }

    /**
     * Remove the specified client.
     */
    public function destroy(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        // BR-CUS-007: clients are hard-deleted, and their foreign keys would null
        // out sales, payments and ledger lines (and cascade-delete quotations).
        // A client with any history is therefore kept; suspend it instead.
        $inUse = ReferenceGuard::firstReference([
            'sales_orders' => 'client_id',
            'sales_returns' => 'client_id',
            'sale_payments' => 'client_id',
            'journal_lines' => 'client_id',
            'quotations' => 'client_id',
            'cutter_work_orders' => 'client_id',
            'credit_approval_requests' => 'client_id',
            'production_batches' => 'requested_by_client_id',
        ], $client->id);

        if ($inUse !== null) {
            return response()->json([
                'message' => "This client has transactions ({$inUse}) and cannot be deleted. Suspend it instead.",
                'code' => 'CLIENT_HAS_TRANSACTIONS',
                'referenced_by' => $inUse,
            ], 422);
        }

        DB::transaction(function () use ($client): void {
            // The entity may also be an employee or supplier; drop only its
            // client role so it does not keep listing as a client.
            $client->entity?->roles()->where('role_type', EntityRoleType::Client->value)->delete();
            $client->delete();
        });

        return response()->json([
            'message' => 'Client deleted successfully.',
        ]);
    }
}
