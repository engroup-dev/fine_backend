<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\JournalEntryType;
use App\Exceptions\LedgerRuleException;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Scopes\OperatingUnitScope;
use App\Rules\LeafAccount;
use App\Services\AccountingService;
use App\Services\TrialBalanceService;
use App\Support\CompanyClock;
use App\Support\CurrentUnitContext;
use App\Support\ReceivableAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    use ResolvesReportScope;

    private const EXPORT_LIMIT = 5000;

    public function __construct(
        public AccountingService $accountingService,
        public CurrentUnitContext $unitContext,
    ) {}

    /**
     * The journal book: filterable by date, type, account, author, amount and
     * source (FR-FREP-014), with each entry's reversal links.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'entry_type' => ['sometimes', 'array'],
            'entry_type.*' => [Rule::enum(JournalEntryType::class)],
            'account_id' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'created_by_user_id' => ['sometimes', 'nullable', 'uuid'],
            'min_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'source_document_type' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $query = JournalEntry::with(['lines.account', 'reversal:id,reference,reversal_of_id', 'reversalOf:id,reference'])
            ->latest('entry_date')
            ->latest('created_at');

        if (filled($request->query('from'))) {
            $query->whereDate('entry_date', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('entry_date', '<=', $request->query('to'));
        }

        if ($request->boolean('manual_only')) {
            $query->whereIn('entry_type', JournalEntryType::manualValues());
        }

        if ($request->filled('entry_type')) {
            $query->whereIn('entry_type', (array) $request->input('entry_type'));
        }

        if ($request->filled('account_id')) {
            $accountId = (string) $request->input('account_id');
            $query->whereHas('lines', fn ($q) => $q->where('account_id', $accountId));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('description', 'like', $term));
        }

        if ($request->filled('created_by_user_id')) {
            $query->where('created_by_user_id', $request->input('created_by_user_id'));
        }

        if ($request->filled('source_document_type')) {
            $query->where('source_document_type', $request->input('source_document_type'));
        }

        foreach (['min_amount' => '>=', 'max_amount' => '<='] as $param => $operator) {
            if ($request->filled($param)) {
                // A formatted numeric literal, not a binding: PDO binds floats as
                // text, which SQLite never compares as greater than a number.
                $amount = sprintf('%.4F', (float) $request->input($param));
                $query->whereRaw(
                    "(select coalesce(sum(debit), 0) from journal_lines where journal_lines.journal_entry_id = journal_entries.id) {$operator} {$amount}"
                );
            }
        }

        // Filtered through lines, since an entry itself is company-level while its
        // lines carry the unit.
        if ($unitId = $this->resolveReportUnitId($request, $this->unitContext)) {
            $query->whereHas('lines', fn ($q) => $q->where('operating_unit_id', $unitId));
        }

        // The journal book print and CSV take every filtered entry at once.
        if ($request->boolean('all')) {
            $total = (clone $query)->count();

            if ($total > self::EXPORT_LIMIT) {
                throw new LedgerRuleException(
                    'Too many entries to export at once; narrow the filters.',
                    'EXPORT_TOO_LARGE',
                    ['limit' => self::EXPORT_LIMIT, 'total' => $total],
                );
            }

            return response()->json(['data' => $query->get(), 'current_page' => 1, 'last_page' => 1, 'total' => $total]);
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * ACC-04: manual entries exist for corrections only, and only from someone
     * accountable for the whole ledger. The entry itself still goes through
     * postJournal, so a manual correction can no more unbalance the books than
     * an auto-posted event can.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('owner') && ! $user->hasRole('accounting-manager')) {
            return response()->json([
                'message' => 'Only an accounting manager or the owner can post manual journal entries.',
                'code' => 'MANUAL_JOURNAL_FORBIDDEN',
            ], 403);
        }

        $validated = $request->validate([
            'entry_type' => ['sometimes', Rule::in(JournalEntryType::manualValues())],
            'description' => 'required|string|max:500',
            'entry_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'lines' => 'required|array|min:2',
            'lines.*.account_code' => ['required', 'string', new LeafAccount('account_code', [ReceivableAccounts::ROOT_CODE], requireActive: true)],
            'lines.*.debit' => 'sometimes|numeric|min:0',
            'lines.*.credit' => 'sometimes|numeric|min:0',
            'lines.*.currency' => 'sometimes|nullable|string|size:3',
            'lines.*.original_amount' => 'sometimes|nullable|numeric|gt:0|required_with:lines.*.currency',
            'lines.*.fx_rate' => 'sometimes|nullable|numeric|gt:0',
            'lines.*.operating_unit_id' => 'sometimes|nullable|uuid|exists:operating_units,id',
            'lines.*.client_id' => 'sometimes|nullable|uuid|exists:clients,id',
            'lines.*.employee_id' => 'sometimes|nullable|uuid|exists:employees,id',
            'lines.*.memo' => 'sometimes|nullable|string|max:500',
        ]);

        try {
            $entry = $this->accountingService->postJournal(
                $validated['description'],
                $this->defaultEmployeeUnits($this->defaultClientUnits($validated['lines'])),
                isManual: true,
                userId: $user->id,
                entryDate: $validated['entry_date'] ?? null,
                entryType: JournalEntryType::from($validated['entry_type'] ?? JournalEntryType::Manual->value),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_JOURNAL',
            ], 422);
        }

        return response()->json($entry->load('createdBy'), 201);
    }

    /**
     * A client-tagged line with no unit takes the client's unit, so it stays
     * visible to unit-scoped receivables reports. The client may belong to
     * another unit than the current context, hence no global scopes.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function defaultClientUnits(array $lines): array
    {
        $clientIds = collect($lines)
            ->filter(fn (array $line) => ($line['client_id'] ?? null) !== null && ($line['operating_unit_id'] ?? null) === null)
            ->pluck('client_id')
            ->unique()
            ->all();

        if ($clientIds === []) {
            return $lines;
        }

        $unitByClient = Client::withoutGlobalScopes()
            ->whereKey($clientIds)
            ->pluck('operating_unit_id', 'id');

        return array_map(function (array $line) use ($unitByClient): array {
            if (($line['client_id'] ?? null) !== null && ($line['operating_unit_id'] ?? null) === null) {
                $line['operating_unit_id'] = $unitByClient[$line['client_id']] ?? null;
            }

            return $line;
        }, $lines);
    }

    /**
     * W5-00: an employee-tagged line with no unit takes the employee's unit, so
     * the advance stays visible to unit-scoped advance balances and payroll
     * recovery. Deleted employees are included so a repayment line still lands
     * in their unit; postJournal decides what a deleted employee may post.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function defaultEmployeeUnits(array $lines): array
    {
        $needsUnit = fn (array $line): bool => ($line['employee_id'] ?? null) !== null && ($line['operating_unit_id'] ?? null) === null;

        $employeeIds = collect($lines)->filter($needsUnit)->pluck('employee_id')->unique()->all();

        if ($employeeIds === []) {
            return $lines;
        }

        $unitByEmployee = Employee::withoutGlobalScope(OperatingUnitScope::class)
            ->withTrashed()
            ->whereKey($employeeIds)
            ->pluck('operating_unit_id', 'id');

        return array_map(function (array $line) use ($needsUnit, $unitByEmployee): array {
            if ($needsUnit($line)) {
                $line['operating_unit_id'] = $unitByEmployee[$line['employee_id']] ?? null;
            }

            return $line;
        }, $lines);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            JournalEntry::with(['lines.account', 'lines.operatingUnit', 'createdBy', 'reversal:id,reference,reversal_of_id', 'reversalOf:id,reference'])
                ->findOrFail($id)
        );
    }

    /**
     * ACC-13: reverse a posted entry with a linked mirror entry. Owner and
     * accounting manager may reverse any entry except closing entries and
     * reversals; reversing an automatic entry fixes the books but does not
     * undo the sale, stock move or payroll behind it.
     */
    public function reverse(Request $request, string $id): JsonResponse
    {
        $original = JournalEntry::findOrFail($id);

        $validated = $request->validate([
            'entry_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.CompanyClock::today()],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $reversal = $this->accountingService->reverseEntry(
            $original,
            $validated['entry_date'] ?? null,
            $validated['reason'],
            $request->user()->id,
        );

        return response()->json($reversal->load(['createdBy', 'reversalOf:id,reference']), 201);
    }

    /**
     * Every journal produced by one business event (ACC-03) — the audit path from
     * a batch, import order or work order to its financial effect.
     */
    public function forDocument(string $type, string $documentId): JsonResponse
    {
        $entries = JournalEntry::with(['lines.account'])
            ->where('source_document_type', $type)
            ->where('source_document_id', $documentId)
            ->latest('entry_date')
            ->get();

        return response()->json($entries);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        // ACC-03: trial balance respects the same scope filter as
        // GET /api/v1/accounts.
        $auth = $this->resolveReportUnitId($request, $this->unitContext);
        $scope = (string) $request->query('scope', $auth !== null ? 'unit' : 'global');

        if ($scope === 'company' && $auth !== null) {
            return response()->json([
                'message' => 'Company-wide scope is restricted to accounting roles.',
                'code' => 'COMPANY_WIDE_FORBIDDEN',
            ], 403);
        }

        if ($scope === 'unit') {
            $unitIdParam = $request->query('unit_id', $auth);
            if (! $unitIdParam) {
                return response()->json([
                    'message' => 'unit_id is required for scope=unit.',
                    'code' => 'UNIT_ID_REQUIRED',
                ], 422);
            }
            if ($auth !== null && (string) $auth !== (string) $unitIdParam) {
                return response()->json([
                    'message' => 'Cross-unit scope forbidden.',
                    'code' => 'CROSS_UNIT_SCOPE',
                ], 403);
            }
            $unitIdForBalance = $unitIdParam;
        } else {
            // scope=global or scope=company → union (no operating_unit_id filter
            // in the trial balance service; existing callers that pass a unit_id
            // through resolveReportUnitId continue to work, and explicitly
            // scope=global forces $unitIdForBalance = null).
            $unitIdForBalance = null;
        }

        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'include_zero' => ['sometimes', 'boolean'],
            'view' => ['sometimes', 'string', 'in:leaf,level,tree'],
            'level' => ['required_if:view,level', 'nullable', 'integer', 'min:1', 'max:10'],
            'exclude_closing' => ['sometimes', 'boolean'],
        ]);

        return response()->json(app(TrialBalanceService::class)->build(
            from: $validated['from'] ?? null,
            to: $validated['to'] ?? null,
            operatingUnitId: $unitIdForBalance,
            includeZero: $request->boolean('include_zero'),
            view: $validated['view'] ?? 'leaf',
            level: isset($validated['level']) ? (int) $validated['level'] : null,
            excludeClosing: $request->boolean('exclude_closing'),
        ));
    }
}
