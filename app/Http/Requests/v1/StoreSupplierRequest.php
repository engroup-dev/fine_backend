<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Rules\LeafAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operating_unit_id' => 'required|uuid|exists:operating_units,id',
            'name' => 'required|string|max:255',
            'contact' => 'nullable|string|max:255',
            'default_currency' => 'sometimes|string|size:3',
            'address' => 'nullable|string',
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true)],
            'coa_action' => 'nullable|string|in:create_new,link_existing,none',
            'new_account' => 'nullable|array',
            'new_account.parent_account_id' => 'required_if:coa_action,create_new|nullable|uuid|exists:accounts,id',
            'new_account.account_code' => 'required_if:coa_action,create_new|nullable|string|max:50|unique:accounts,account_code',
            'new_account.name' => 'required_if:coa_action,create_new|nullable|string|max:255',
            'new_account.currency' => 'nullable|string|size:3',
            // W5-07: link an existing entity (a client's, for example) instead
            // of creating a new one; force skips the duplicate check.
            'entity_id' => ['nullable', 'uuid', Rule::exists('entities', 'id')->whereNull('deleted_at')],
            'tax_number' => 'nullable|string|max:100',
            'force' => 'sometimes|boolean',
        ];
    }
}
