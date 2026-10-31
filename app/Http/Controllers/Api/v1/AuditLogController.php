<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AuditLogController extends Controller
{
    /** 'merged' is SupplierService::merge's row on the surviving supplier (W5-07). */
    private const VALID_ACTIONS = ['created', 'updated', 'deleted', 'restored', 'merged'];

    /**
     * Display a paginated listing of audit logs, newest first, with optional
     * filters on table, user, action and date range.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'table_name' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'uuid'],
            'action' => ['sometimes', 'string', Rule::in(self::VALID_ACTIONS)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AuditLog::latest();

        foreach (['table_name', 'user_id', 'action'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        return AuditLogResource::collection($query->paginate($validated['per_page'] ?? 50));
    }

    /**
     * Display audit logs associated with a specific database record, with
     * optional filters on action type and date range.
     */
    public function show(Request $request, string $tableName, string $recordId): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', Rule::in(self::VALID_ACTIONS)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $query = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest();

        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        return AuditLogResource::collection($query->get());
    }
}
