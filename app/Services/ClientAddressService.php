<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SalesRuleException;
use App\Models\Client;
use App\Models\ClientAddress;
use Illuminate\Support\Facades\DB;

/**
 * A client's delivery address book. At most one address is the default: the
 * first address becomes the default, choosing another default clears the
 * previous one, and removing the default promotes the oldest remaining one.
 * The default cannot be switched off directly: another address is chosen as
 * the default instead, so a client with addresses always has one.
 * The client row is locked so concurrent edits cannot leave two defaults.
 */
final class ClientAddressService
{
    /**
     * @param  array{label: string, city?: string|null, address: string, phone?: string|null, is_default?: bool|null}  $data
     */
    public function create(Client $client, array $data): ClientAddress
    {
        return DB::transaction(function () use ($client, $data): ClientAddress {
            $this->lockClient($client);

            $isFirst = ! ClientAddress::query()->where('client_id', $client->id)->exists();
            $isDefault = $isFirst || (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                $this->clearDefault($client);
            }

            return ClientAddress::create([
                'client_id' => $client->id,
                'label' => $data['label'],
                'city' => $data['city'] ?? null,
                'address' => $data['address'],
                'phone' => $data['phone'] ?? null,
                'is_default' => $isDefault,
            ]);
        });
    }

    /**
     * @param  array{label?: string, city?: string|null, address?: string, phone?: string|null, is_default?: bool|null, record_version: int}  $data
     *
     * @throws SalesRuleException ADDRESS_DEFAULT_REQUIRED when the default is switched off
     */
    public function update(ClientAddress $address, array $data): ClientAddress
    {
        return DB::transaction(function () use ($address, $data): ClientAddress {
            $client = $address->client()->firstOrFail();
            $this->lockClient($client);

            $locked = ClientAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            if (($data['is_default'] ?? null) === false && $locked->is_default) {
                throw new SalesRuleException(
                    'This is the default address; choose another address as the default instead.',
                    'ADDRESS_DEFAULT_REQUIRED',
                );
            }

            if (($data['is_default'] ?? false) === true && ! $locked->is_default) {
                $this->clearDefault($client, except: $locked->id);
            }

            $locked->update(array_intersect_key($data, array_flip([
                'label', 'city', 'address', 'phone', 'is_default', 'record_version',
            ])));

            return $locked->refresh();
        });
    }

    public function delete(ClientAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $client = $address->client()->firstOrFail();
            $this->lockClient($client);

            $locked = ClientAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $wasDefault = $locked->is_default;
            $locked->delete();

            if ($wasDefault) {
                $next = ClientAddress::query()
                    ->where('client_id', $client->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first();
                $next?->update(['is_default' => true]);
            }
        });
    }

    private function lockClient(Client $client): void
    {
        Client::withoutGlobalScopes()->whereKey($client->id)->lockForUpdate()->firstOrFail();
    }

    private function clearDefault(Client $client, ?string $except = null): void
    {
        ClientAddress::query()
            ->where('client_id', $client->id)
            ->where('is_default', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get()
            ->each(fn (ClientAddress $other) => $other->update(['is_default' => false]));
    }
}
