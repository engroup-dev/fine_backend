<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\DeductionAccountRole;
use App\Enums\PayItemKind;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ReferenceLookup;
use App\Services\EmployeePayItemService;
use App\Support\ReferenceGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReferenceLookupController extends Controller
{
    public function index(Request $request, string $category): JsonResponse
    {
        $query = ReferenceLookup::query()->where('category', $category);

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('reference_lookups', 'code')->where('category', $category),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'fields' => ['nullable', 'array'],
        ]);

        self::validateFields($request, $category);

        $item = ReferenceLookup::create([
            ...$validated,
            'category' => $category,
        ]);

        return response()->json($item, 201);
    }

    public function show(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('reference_lookups', 'code')
                    ->where('category', $category)
                    ->ignore($item->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'fields' => ['nullable', 'array'],
        ]);

        self::validateFields($request, $category);

        // Pay codes match case-insensitively (the desktop sends them upper-cased).
        if (array_key_exists('code', $validated) && strtoupper(trim((string) $validated['code'])) !== strtoupper(trim((string) $item->code))
            && ($refusal = $this->payCodeInUseRefusal($category, $item, 'renamed')) !== null) {
            return $refusal;
        }

        $oldRole = $item->fields['account_role'] ?? null;

        $item->update($validated);

        // HR-13: a calculated run priced this type's lines with the old role.
        if ($category === PayItemKind::Deduction->lookupCategory() && ($item->fields['account_role'] ?? null) !== $oldRole) {
            app(EmployeePayItemService::class)->flagRunsUsingDeductionCode((string) $item->code);
        }

        return response()->json($item);
    }

    public function destroy(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        if ($category === 'currencies' && ($refusal = $this->currencyDeleteRefusal($item)) !== null) {
            return $refusal;
        }

        if (($refusal = $this->payCodeInUseRefusal($category, $item, 'deleted')) !== null) {
            return $refusal;
        }

        $item->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * W5-03 (HR-13): a deduction type's account_role routes it in payroll
     * (liability → 225, expense_reduction → 57); nothing else is accepted.
     * Validated on its own so `fields` keeps every other key.
     */
    private static function validateFields(Request $request, string $category): void
    {
        if ($category !== PayItemKind::Deduction->lookupCategory()) {
            return;
        }

        $request->validate(['fields.account_role' => ['sometimes', 'nullable', Rule::enum(DeductionAccountRole::class)]]);
    }

    /**
     * HR-13: standing pay items and adjustments name their allowance or
     * deduction type by code, and payroll routes a deduction by that type's
     * account role; renaming or deleting a type in use would orphan them and
     * silently move their deductions to 225.
     */
    private function payCodeInUseRefusal(string $category, ReferenceLookup $item, string $action): ?JsonResponse
    {
        $payCategories = [PayItemKind::Addition->lookupCategory(), PayItemKind::Deduction->lookupCategory()];

        if (! in_array($category, $payCategories, true)) {
            return null;
        }

        $kind = $category === PayItemKind::Addition->lookupCategory() ? PayItemKind::Addition : PayItemKind::Deduction;
        $inUse = app(EmployeePayItemService::class)->codeReference((string) $item->code, $kind);

        if ($inUse === null) {
            return null;
        }

        return response()->json([
            'message' => "{$item->code} is used by employee pay items or adjustments ({$inUse}) and cannot be {$action}. Deactivate it instead.",
            'code' => 'PAY_CODE_IN_USE',
            'referenced_by' => $inUse,
        ], 422);
    }

    /**
     * BR-CUR-002: the base currency, and any currency already used on accounts,
     * treasuries or documents, cannot be deleted.
     */
    private function currencyDeleteRefusal(ReferenceLookup $currency): ?JsonResponse
    {
        $code = strtoupper((string) ($currency->code ?: $currency->name));
        $baseCurrency = strtoupper((string) (Company::query()->value('default_currency') ?? 'LYD'));

        if ($code === $baseCurrency) {
            return response()->json([
                'message' => "{$code} is the base currency and cannot be deleted.",
                'code' => 'BASE_CURRENCY',
            ], 422);
        }

        $inUse = ReferenceGuard::firstReference([
            'accounts' => 'currency',
            'cash_accounts' => 'currency',
            'operating_units' => 'currency',
            'purchase_orders' => 'currency',
            'purchase_order_items' => 'currency',
            'landed_cost_lines' => 'currency',
            'overhead_expenses' => 'currency',
            'fx_rates' => ['from_currency', 'to_currency'],
        ], $code);

        if ($inUse === null) {
            return null;
        }

        return response()->json([
            'message' => "Currency {$code} is in use ({$inUse}) and cannot be deleted. Deactivate it instead.",
            'code' => 'CURRENCY_IN_USE',
            'referenced_by' => $inUse,
        ], 422);
    }

    public function toggleActive(string $category, int|string $id): JsonResponse
    {
        $item = ReferenceLookup::query()
            ->where('category', $category)
            ->findOrFail($id);

        $item->update([
            'is_active' => ! $item->is_active,
        ]);

        return response()->json($item);
    }
}
