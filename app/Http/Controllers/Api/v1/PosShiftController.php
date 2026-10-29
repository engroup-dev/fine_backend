<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\OperatingUnit;
use App\Models\PosShift;
use App\Services\PosShiftService;
use App\Support\CompanyClock;
use App\Support\CurrentUnitContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * FR-SLREP-008: cashier shifts — open with a float, X-report at any time,
 * close with a counted Z-report.
 */
final class PosShiftController extends Controller
{
    private const RELATIONS = [
        'cashier:id,name',
        'closedBy:id,name',
        'cashAccount:id,name,kind',
    ];

    /** Role slugs that manage a unit (both spellings RequireRole accepts). */
    private const UNIT_MANAGER_ROLES = ['store-manager', 'store_manager', 'unit_manager', 'unit-manager', 'manager'];

    public function __construct(
        private readonly PosShiftService $shifts,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([PosShift::STATUS_OPEN, PosShift::STATUS_CLOSED])],
            'cash_account_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = PosShift::with(self::RELATIONS)->latest('opened_at');

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('cash_account_id'))) {
            $query->where('cash_account_id', $request->query('cash_account_id'));
        }

        // from/to are company-local days (as the register day is); opened_at is UTC.
        $timezone = CompanyClock::timezone($this->unitContext->unit());

        if (filled($request->query('from'))) {
            $query->where('opened_at', '>=', CarbonImmutable::parse($request->query('from'), $timezone)->startOfDay()->utc());
        }

        if (filled($request->query('to'))) {
            $query->where('opened_at', '<=', CarbonImmutable::parse($request->query('to'), $timezone)->endOfDay()->utc());
        }

        return response()->json($query->paginate(min($request->integer('per_page', 20), 100)));
    }

    /**
     * The drawer's open shift in this unit, with its running totals, or null.
     * Another unit's shift on a shared drawer is not this unit's: checkout
     * refuses cash through it (NO_OPEN_SHIFT) and opening one here reports
     * it (SHIFT_ALREADY_OPEN).
     */
    public function current(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
        ]);

        $shift = $this->shifts->currentFor(
            CashAccount::findOrFail($validated['cash_account_id']),
            $this->unitContext->getUnitId(),
        );

        if ($shift === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->present($shift)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cash_account_id' => ['required', 'uuid', 'exists:cash_accounts,id'],
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        $unit = $this->unitContext->unit();

        if ($unit === null) {
            return response()->json([
                'message' => 'Select an operating unit before opening a shift.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $shift = $this->shifts->open(
            $unit,
            CashAccount::with('account')->findOrFail($validated['cash_account_id']),
            (float) $validated['opening_float'],
            $request->user(),
        );

        return response()->json($this->present($shift), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->present(PosShift::findOrFail($id)));
    }

    /**
     * X-report: the shift's figures right now, without closing it.
     */
    public function xReport(string $id): JsonResponse
    {
        $shift = PosShift::with(self::RELATIONS)->findOrFail($id);

        return response()->json([
            'shift' => $shift,
            'totals' => $this->shifts->totals($shift),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shifts->close(
            PosShift::findOrFail($id),
            (float) $validated['counted_cash'],
            $validated['notes'] ?? null,
            $request->user(),
        );

        return response()->json($this->present($shift));
    }

    /**
     * FR-SLREP-008: owner and unit managers switch cashier shifts on or off
     * for a unit they manage.
     */
    public function updateSettings(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'pos_shifts_enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $unit = OperatingUnit::findOrFail($id);

        // The route admits only owner, admin and manager roles; owner and
        // admin pass as everywhere else (RequireRole), a manager must hold
        // that role in this unit (or company-wide), or be the unit's manager.
        $allowed = $user->hasRole('owner')
            || $user->hasRole('admin')
            || $unit->manager_user_id === $user->id
            || $user->roles()
                ->whereIn('slug', self::UNIT_MANAGER_ROLES)
                ->where(fn ($q) => $q->where('user_roles.operating_unit_id', $unit->id)->orWhereNull('user_roles.operating_unit_id'))
                ->exists();

        if (! $allowed) {
            return response()->json([
                'message' => 'You do not manage this operating unit.',
                'code' => 'ACCESS_DENIED',
            ], 403);
        }

        $this->shifts->ensureSettingChangeAllowed($unit, (bool) $validated['pos_shifts_enabled']);

        $unit->update(['pos_shifts_enabled' => (bool) $validated['pos_shifts_enabled']]);

        return response()->json([
            'id' => $unit->id,
            'pos_shifts_enabled' => (bool) $unit->pos_shifts_enabled,
        ]);
    }

    /**
     * A shift with its people, drawer, journal entry and totals — stored at
     * close, live while open.
     *
     * @return array<string, mixed>
     */
    private function present(PosShift $shift): array
    {
        $shift->loadMissing(self::RELATIONS);

        return [
            ...$shift->toArray(),
            'totals' => $shift->isOpen() ? $this->shifts->totals($shift) : $shift->totals,
        ];
    }
}
