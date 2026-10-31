<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ClientAddressResource;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Services\ClientAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A client's delivery addresses. The client is resolved through its unit
 * scope first, so a caller only reaches addresses of clients in their unit.
 */
final class ClientAddressController extends Controller
{
    public function __construct(private readonly ClientAddressService $addresses) {}

    public function index(string $clientId): AnonymousResourceCollection
    {
        $client = Client::findOrFail($clientId);

        return ClientAddressResource::collection(
            $client->addresses()->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->get()
        );
    }

    public function store(Request $request, string $clientId): JsonResponse
    {
        $client = Client::findOrFail($clientId);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return (new ClientAddressResource($this->addresses->create($client, $validated)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $clientId, string $addressId): ClientAddressResource
    {
        $address = $this->find($clientId, $addressId);

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
            'record_version' => ['required', 'integer'],
        ]);

        if (array_key_exists('is_default', $validated)) {
            $validated['is_default'] = (bool) $validated['is_default'];
        }

        return new ClientAddressResource($this->addresses->update($address, $validated));
    }

    public function destroy(string $clientId, string $addressId): JsonResponse
    {
        $this->addresses->delete($this->find($clientId, $addressId));

        return response()->json(['message' => 'Address deleted.']);
    }

    private function find(string $clientId, string $addressId): ClientAddress
    {
        $client = Client::findOrFail($clientId);

        return $client->addresses()->whereKey($addressId)->firstOrFail();
    }
}
