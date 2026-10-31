<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DebtorStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'client_ids' => ['sometimes', 'array'],
            'client_ids.*' => ['uuid'],
            'statuses' => ['sometimes', 'array'],
            'statuses.*' => [Rule::enum(ClientStatus::class)],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'min_balance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'include_unassigned' => ['sometimes', 'boolean'],
            'client_group_id' => ['sometimes', 'nullable', 'uuid'],
            'company_wide' => ['sometimes', 'boolean'],
            'operating_unit_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
