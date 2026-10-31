<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\AuditLogResource;
use App\Models\AuditLog;
use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;

final class AuditLogController extends Controller
{
    /** 'merged' is SupplierService::merge's row on the surviving supplier (W5-07). */
    private const VALID_ACTIONS = ['created', 'updated', 'deleted', 'restored', 'merged'];

    /** Roles that may read the audit log (the route's require.role:owner,admin). */
    private const READER_ROLES = ['owner', 'admin'];

    public function __construct(
        private readonly CurrentUnitContext $unitContext,
    ) {}

    /**
     * Display a paginated listing of audit logs, newest first, with optional
     * filters on table, record, unit, user, action, date range and free-text search.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'table_name' => ['sometimes', 'string', 'max:100'],
            'record_id' => ['sometimes', 'uuid'],
            'operating_unit_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', Rule::in(self::VALID_ACTIONS)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->withUserName(AuditLog::latest());
        $this->scopeToUnit($request, $query);

        foreach (['table_name', 'record_id', 'operating_unit_id', 'user_id', 'action'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (isset($validated['search']) && trim($validated['search']) !== '') {
            $this->applySearch($query, trim($validated['search']));
        }

        $this->applyDateRange($query, $validated);

        return AuditLogResource::collection($query->paginate($validated['per_page'] ?? 50));
    }

    /**
     * W6-01: the distinct table names that have audit rows, for the viewer's table filter.
     */
    public function tables(Request $request): JsonResponse
    {
        $query = AuditLog::query();
        $this->scopeToUnit($request, $query);

        $tables = $query
            ->distinct()
            ->orderBy('table_name')
            ->pluck('table_name')
            ->values();

        return response()->json(['data' => $tables]);
    }

    /**
     * Display audit logs associated with a specific database record, with
     * optional filters on action type and date range. record_id is a uuid column,
     * so a non-uuid {recordId} cannot match any row: it returns the same empty list
     * as an unknown id, without sending the value to the database (pgsql rejects
     * it with 22P02).
     */
    public function show(Request $request, string $tableName, string $recordId): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', Rule::in(self::VALID_ACTIONS)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $query = $this->withUserName(AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest());
        $this->scopeToUnit($request, $query);

        if (! Str::isUuid($recordId)) {
            return AuditLogResource::collection(collect());
        }

        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        $this->applyDateRange($query, $validated);

        return AuditLogResource::collection($query->get());
    }

    /**
     * Eager-load the acting user's id and name (soft-deleted users keep their name on the log).
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function withUserName(Builder $query): Builder
    {
        return $query->with(['user' => fn ($user) => $user->withTrashed()->select('id', 'name')]);
    }

    /**
     * Access is decided by the owner/admin role itself, not by the user's other roles
     * (RequireRole passes a user who is owner or admin in ANY unit, and ScopeOperatingUnit
     * accepts a unit header if the user holds ANY role there):
     *  - an owner or admin role with no unit (user_roles.operating_unit_id null) reads
     *    every unit's history, company-level rows included;
     *  - otherwise the request must carry a unit the user is owner or admin of, and only
     *    that unit's rows are returned (403 for a unit where they hold some other role).
     *
     * The rows are matched on audit_logs.operating_unit_id, which AuditObserver fills from
     * the unit context in force when the change was made (the actor's X-Operating-Unit-ID),
     * not from the unit the changed record belongs to. A unit admin therefore sees the
     * changes made while working in their unit; changes a company-wide user made in another
     * unit's context, or with no unit selected, are not shown to them.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function scopeToUnit(Request $request, Builder $query): void
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $readerRoles = fn () => $user->roles()->whereIn('slug', self::READER_ROLES);

        if ($readerRoles()->whereNull('user_roles.operating_unit_id')->exists()) {
            return;
        }

        $unitId = $this->unitContext->id();

        // Never fall through to where(..., null), which would match the company-level rows.
        abort_if(
            $unitId === null || ! $readerRoles()->where('user_roles.operating_unit_id', $unitId)->exists(),
            403,
            'You do not have access to this audit log.',
        );

        $query->where('operating_unit_id', $unitId);
    }

    /**
     * Case-insensitive free-text search over the table name, the record id, the
     * acting user's name and the logged values. The term is matched literally:
     * %, _ and ! in it are escaped, not treated as LIKE wildcards. Values are
     * stored as JSON with non-ASCII characters escaped (\uXXXX), so the term is
     * also matched in that escaped form - skipped when the term is not valid
     * UTF-8 and cannot be encoded.
     *
     * Portable across sqlite, pgsql and mysql: the escape character is '!' (a
     * backslash in ESCAPE '\' breaks MySQL's default sql_mode), and the uuid and
     * json columns are cast to text first (pgsql has no uuid/json LIKE operator).
     * Both sides are wrapped in LOWER() because LIKE is case-sensitive on pgsql
     * (sqlite and mysql's default collations already ignore case).
     *
     * @param  Builder<AuditLog>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $term = $this->likePattern($search);

        try {
            $encoded = substr(json_encode($search, JSON_THROW_ON_ERROR), 1, -1);
        } catch (JsonException) {
            $encoded = '';
        }

        $escaped = $encoded !== '' && $encoded !== $search ? $this->likePattern($encoded) : null;

        $textType = $query->getConnection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';
        $asText = fn (string $column): string => "CAST({$column} AS {$textType})";
        $like = fn (string $expression): string => "LOWER({$expression}) LIKE LOWER(?) ESCAPE '!'";

        $query->where(function (Builder $q) use ($term, $escaped, $asText, $like): void {
            $q->whereRaw($like('table_name'), [$term])
                ->orWhereRaw($like($asText('record_id')), [$term])
                ->orWhereHas('user', fn (Builder $user) => $user->withTrashed()->whereRaw($like('name'), [$term]))
                ->orWhereRaw($like($asText('old_values')), [$term])
                ->orWhereRaw($like($asText('new_values')), [$term]);

            if ($escaped !== null) {
                $q->orWhereRaw($like($asText('old_values')), [$escaped])
                    ->orWhereRaw($like($asText('new_values')), [$escaped]);
            }
        });
    }

    /**
     * Wrap a literal in % for a LIKE ... ESCAPE '!' match, escaping its own wildcards.
     */
    private function likePattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $validated
     */
    private function applyDateRange(Builder $query, array $validated): void
    {
        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }
    }
}
