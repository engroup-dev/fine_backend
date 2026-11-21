<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\CashAccountResource;
use App\Models\CashAccount;
use App\Models\User;
use App\Rules\LeafAccount;
use App\Services\AccountLedgerService;
use App\Services\TreasuryBalanceService;
use App\Services\TreasuryOpeningBalanceService;
use App\Support\EmployeeAdvanceAccounts;
use App\Support\TreasuryAccounts;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CashAccountController extends Controller
{
    public function __construct(
        private readonly TreasuryBalanceService $treasuryBalanceService,
        private readonly TreasuryOpeningBalanceService $openingBalances,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashAccount::with('account');

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if (filled($request->query('kind'))) {
            $query->where('kind', $request->query('kind'));
        }

        $accounts = $query->orderBy('name')->get();
        $this->treasuryBalanceService->attach($accounts);

        return CashAccountResource::collection($accounts);
    }

    /**
     * The treasuries money can move through: linked to a ledger leaf, with
     * live ledger_balance for POS and voucher pickers (TRS-01).
     */
    public function options(Request $request): JsonResponse
    {
        $query = CashAccount::with('account:id,account_code,name')->whereNotNull('account_id');

        if (filled($request->query('kind'))) {
            $query->where('kind', $request->query('kind'));
        }

        $accounts = $query->orderBy('name')->get();
        $balances = $this->treasuryBalanceService->balances($accounts);

        return response()->json([
            'data' => $accounts->map(fn (CashAccount $cashAccount): array => [
                'id' => $cashAccount->id,
                'name' => $cashAccount->name,
                'kind' => $cashAccount->kind,
                'currency' => $cashAccount->currency,
                'operating_unit_id' => $cashAccount->operating_unit_id,
                'account_code' => $cashAccount->account?->account_code,
                'ledger_balance' => $balances[$cashAccount->id]['ledger_balance'] ?? 0.0,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'operating_unit_id' => 'required|uuid|exists:operating_units,id',
            'name' => 'required|string|max:255',
            'kind' => 'sometimes|string|in:cash,bank',
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true), $this->notEmployeeAdvance()],
            'currency' => 'sometimes|string|size:3',
            'balance' => 'sometimes|numeric',
            'opening_fx_rate' => 'nullable|numeric|gt:0',
            'opening_date' => 'nullable|date|before_or_equal:today',
        ]);

        $this->assertKindBranch($request->input('account_id'), $request->input('kind', CashAccount::KIND_CASH));

        // The opening balance posts with the treasury or not at all (TRS-01).
        $account = DB::transaction(function () use ($request): CashAccount {
            $account = CashAccount::create([
                'operating_unit_id' => $request->input('operating_unit_id'),
                'name' => $request->input('name'),
                'kind' => $request->input('kind', CashAccount::KIND_CASH),
                'account_id' => $request->input('account_id'),
                'currency' => strtoupper($request->input('currency', 'LYD')),
                'balance' => $request->input('balance', 0),
            ]);

            $this->postOpening($account, $request, $request->user());

            return $account;
        });

        $account->load('account');
        $this->treasuryBalanceService->attach([$account]);

        return (new CashAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename a treasury, change its kind, or link it to its ledger account.
     * The opening balance is fixed at creation; a treasury created unlinked
     * posts it when it is first linked.
     */
    public function update(Request $request, string $id): CashAccountResource
    {
        $account = CashAccount::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'kind' => 'sometimes|string|in:cash,bank',
            'account_id' => ['sometimes', 'nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true, unchangedValue: $account->account_id), $this->notEmployeeAdvance($account->account_id)],
            'opening_fx_rate' => 'nullable|numeric|gt:0',
            'opening_date' => 'nullable|date|before_or_equal:today',
        ]);
        unset($validated['opening_fx_rate'], $validated['opening_date']);

        $kind = $validated['kind'] ?? $account->kind;
        $accountId = array_key_exists('account_id', $validated) ? $validated['account_id'] : $account->account_id;
        if ($kind !== $account->kind || $accountId !== $account->account_id) {
            $this->assertKindBranch($accountId, $kind);
        }

        DB::transaction(function () use ($account, $validated, $request): void {
            $account->update($validated);
            $this->postOpening($account, $request, $request->user());
        });
        $account->load('account');
        $this->treasuryBalanceService->attach([$account]);

        return new CashAccountResource($account);
    }

    /**
     * CR 8: a bank links under 1212 and a cash box never does, so cash and
     * bank totals never mix. Existing links are only checked when they change.
     *
     * @throws ValidationException
     */
    private function assertKindBranch(?string $accountId, string $kind): void
    {
        if ($accountId === null || TreasuryAccounts::accountMatchesKind($accountId, $kind)) {
            return;
        }

        $message = $kind === CashAccount::KIND_BANK
            ? 'A bank account must be linked to a ledger account under '.TreasuryAccounts::BANK_ROOT.'.'
            : 'A cash treasury cannot be linked to a bank account (under '.TreasuryAccounts::BANK_ROOT.').';

        throw ValidationException::withMessages(['account_id' => [$message]]);
    }

    private function postOpening(CashAccount $account, Request $request, ?User $user): void
    {
        if ($account->account_id === null) {
            return;
        }

        $fxRate = $request->input('opening_fx_rate');

        $this->openingBalances->post(
            $account,
            fxRate: $fxRate !== null ? (float) $fxRate : null,
            entryDate: $request->input('opening_date'),
            user: $user,
        );
    }

    /**
     * Treasury statement: returns the statement for the linked account,
     * or 422 TREASURY_NOT_LINKED if unlinked (TRS-01).
     */
    public function statement(
        Request $request,
        string $id,
        AccountLedgerService $ledger,
    ): JsonResponse {
        $cashAccount = CashAccount::with('account')->findOrFail($id);

        if ($cashAccount->account_id === null || $cashAccount->account === null) {
            return response()->json([
                'message' => 'This treasury is not linked to a ledger account.',
                'code' => 'TREASURY_NOT_LINKED',
            ], 422);
        }

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'order' => ['sometimes', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $account = $cashAccount->account;

        return response()->json($ledger->statement(
            $account,
            [$account->id],
            from: $validated['from'] ?? null,
            to: $validated['to'] ?? null,
            // Treasuries are company-wide, so the statement is too; it then ends on the card's balance.
            operatingUnitId: null,
            search: $validated['search'] ?? null,
            order: $validated['order'] ?? 'asc',
            perPage: (int) ($validated['per_page'] ?? 25),
            page: (int) ($validated['page'] ?? 1),
            all: $request->boolean('all'),
            currency: $validated['currency'] ?? null,
        ));
    }

    /**
     * W5-00: the employee advances subtree (1215) is not a treasury. Every line
     * on it needs an employee, so a treasury linked there could never post. An
     * existing link is left alone so the rest of the form can still be saved.
     */
    private function notEmployeeAdvance(?string $unchangedValue = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($unchangedValue): void {
            if (is_string($value) && $value !== $unchangedValue && EmployeeAdvanceAccounts::contains($value)) {
                $fail('An employee advances account cannot be a treasury ledger account.');
            }
        };
    }
}
