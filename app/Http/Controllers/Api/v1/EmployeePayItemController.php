<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\PayItemCalculation;
use App\Enums\PayItemKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\v1\EmployeePayItemResource;
use App\Http\Resources\v1\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeePayItem;
use App\Services\EmployeePayItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * W5-03 (HR-13): an employee's standing allowances and deductions. Anyone
 * in HR reads them; only the roles that set pay (HR-10: HR manager,
 * accounting manager, owner) change them.
 */
final class EmployeePayItemController extends Controller
{
    public function __construct(private readonly EmployeePayItemService $payItems) {}

    public function index(Request $request, string $id): AnonymousResourceCollection
    {
        $request->validate(['active' => ['sometimes', 'boolean']]);

        $employee = Employee::findOrFail($id);

        $query = EmployeePayItem::query()
            ->where('employee_id', $employee->id)
            ->with('creator:id,name')
            ->orderByDesc('effective_from')
            ->orderBy('created_at');

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return EmployeePayItemResource::collection($query->get());
    }

    public function store(Request $request, string $id): JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'kind' => ['required', Rule::enum(PayItemKind::class)],
            'code' => ['required', 'string', 'max:64'],
            'calc' => ['required', Rule::enum(PayItemCalculation::class)],
            'value' => ['required', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->payItems->createItem($employee, $validated, $request->user()->id);

        return (new EmployeePayItemResource($item->load('creator:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $id, string $itemId): EmployeePayItemResource|JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        $item = $this->findItem($id, $itemId);

        $validated = $request->validate([
            'kind' => ['sometimes', Rule::enum(PayItemKind::class)],
            'code' => ['sometimes', 'string', 'max:64'],
            'calc' => ['sometimes', Rule::enum(PayItemCalculation::class)],
            'value' => ['sometimes', 'numeric', 'gt:0'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'record_version' => ['sometimes', 'integer'],
        ]);

        $item = $this->payItems->updateItem($item, $validated);

        return new EmployeePayItemResource($item->load('creator:id,name'));
    }

    public function destroy(Request $request, string $id, string $itemId): JsonResponse
    {
        if (! EmployeeResource::holdsPayRole($request->user())) {
            return $this->forbidden();
        }

        $this->payItems->deleteItem($this->findItem($id, $itemId));

        return response()->json(['message' => 'Deleted successfully']);
    }

    /**
     * Unit-scoped through the employee.
     */
    private function findItem(string $employeeId, string $itemId): EmployeePayItem
    {
        $employee = Employee::findOrFail($employeeId);

        return EmployeePayItem::query()->where('employee_id', $employee->id)->findOrFail($itemId);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Only HR, accounting or the owner can change standing allowances and deductions.',
            'code' => 'PAY_ITEM_FORBIDDEN',
        ], 403);
    }
}
