<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\ClientStatus;
use App\Enums\EntityType;
use App\Rules\LeafAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'uuid', 'exists:entities,id', 'unique:clients,entity_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', Rule::enum(EntityType::class)],
            'tax_number' => ['nullable', 'string', 'max:50'],
            // Optional: a POS quick-add omits it and the client joins the
            // caller's current unit.
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            // W5-06: a new client takes its group's credit limit and terms
            // for whichever of the two the caller leaves out.
            'client_group_id' => ['nullable', 'uuid', Rule::exists('client_groups', 'id')->whereNull('deleted_at')],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id', new LeafAccount(requireActive: true)],
            'coa_action' => ['nullable', 'string', 'in:auto,create_new,link_existing,none'],
            'new_account' => ['nullable', 'array'],
            'new_account.parent_account_id' => ['required_if:coa_action,create_new', 'nullable', 'uuid', 'exists:accounts,id'],
            'new_account.account_code' => ['required_if:coa_action,create_new', 'nullable', 'string', 'max:50', 'unique:accounts,account_code'],
            'new_account.name' => ['required_if:coa_action,create_new', 'nullable', 'string', 'max:255'],
            'new_account.currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', Rule::enum(ClientStatus::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
