<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\EmployeeStatus;
use App\Enums\EntityType;
use App\Enums\PayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'uuid', 'exists:entities,id', 'unique:employees,entity_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', Rule::enum(EntityType::class)],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'job_title' => ['required', 'string', 'max:150'],
            'labor_role' => ['nullable', 'string', 'max:100'],
            'employee_number' => ['nullable', 'string', 'max:50', 'unique:employees,employee_number'],
            // Piece-rate pay is not supported by payroll, so it cannot be chosen.
            'pay_type' => ['required', Rule::enum(PayType::class)->except([PayType::PieceRate])],
            // Same rules as a dated pay change (POST /employees/{id}/compensations).
            'monthly_salary' => ['nullable', 'required_if:pay_type,'.PayType::Monthly->value, 'numeric', 'min:0'],
            // An hourly employee with no labor role has no role rate to fall back on.
            'hourly_rate' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('pay_type') === PayType::Hourly->value && blank($this->input('labor_role'))),
                'numeric',
                'min:0',
            ],
            'hire_date' => ['required', 'date'],
            'contract_start' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date'],
            'probation_end' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pay_type.enum' => 'The pay type must be monthly or hourly; piece-rate pay is not supported.',
            'hourly_rate.required' => 'An hourly employee without a labor role needs a personal hourly rate.',
        ];
    }
}
