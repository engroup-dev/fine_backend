<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\InstallmentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * W5-08 (decision #6): the due / overdue installments report across the
 * unit's receivable sales on installments.
 */
final class InstallmentController extends Controller
{
    public function __construct(private readonly InstallmentPlanService $installments) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(InstallmentPlanService::REPORT_STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->installments->report($validated));
    }
}
