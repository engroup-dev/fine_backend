<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ClientGroupResource;
use App\Models\Client;
use App\Models\ClientGroup;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * W5-06: company-wide client groups. Everyone who works with clients reads
 * them (the client form and the POS need them). Writes are limited to
 * managers by the routes, and further to company-wide assignments here: a
 * group sets the credit limit and terms new clients in every unit inherit,
 * so a manager scoped to one unit may not change it (CLIENT_GROUP_FORBIDDEN).
 * The company-wide assignment must itself be a write role: a unit-scoped
 * manager who also holds some other role company-wide is still refused.
 */
final class ClientGroupController extends Controller
{
    /** Roles that may write groups; the routes use the same list. */
    public const WRITE_ROLES = ['owner', 'store-manager', 'accounting-manager', 'unit_manager', 'manager'];

    public function index(): AnonymousResourceCollection
    {
        return ClientGroupResource::collection(ClientGroup::query()->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCompanyWide($request);

        $validated = $request->validate($this->rules());

        $group = ClientGroup::create($validated);

        return (new ClientGroupResource($group->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Group defaults apply when a client is registered; changing them later
     * leaves existing clients' limits and terms as they are.
     */
    public function update(Request $request, string $id): ClientGroupResource
    {
        $this->ensureCompanyWide($request);

        $group = ClientGroup::findOrFail($id);

        $validated = $request->validate([
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], $this->rules($group->id)),
            'record_version' => ['required', 'integer'],
        ]);

        $group->update($validated);

        return new ClientGroupResource($group->refresh());
    }

    /**
     * A group still assigned to a client (in any unit) is refused
     * (CLIENT_GROUP_IN_USE): move its clients to another group first.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->ensureCompanyWide($request);

        return DB::transaction(function () use ($id) {
            $group = ClientGroup::query()->lockForUpdate()->findOrFail($id);

            $clientsCount = Client::withoutGlobalScopes()->where('client_group_id', $group->id)->count();

            if ($clientsCount > 0) {
                return response()->json([
                    'message' => 'The group still has clients; move them to another group first.',
                    'code' => 'CLIENT_GROUP_IN_USE',
                    'clients_count' => $clientsCount,
                ], 422);
            }

            $group->delete();

            return response()->json(['message' => 'Client group deleted.']);
        });
    }

    private function ensureCompanyWide(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && ($user->hasRole('owner') || $user->roles()
            ->whereNull('user_roles.operating_unit_id')
            ->whereIn('roles.slug', self::WRITE_ROLES)
            ->exists())) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Client groups apply to every unit; changing them needs a company-wide role.',
            'code' => 'CLIENT_GROUP_FORBIDDEN',
        ], 403));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?string $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('client_groups', 'code')->whereNull('deleted_at')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:100'],
            'default_credit_limit' => ['numeric', 'min:0'],
            'default_payment_terms_days' => ['integer', 'min:0', 'max:3650'],
            'default_price_tier' => [Rule::in(ClientGroup::PRICE_TIERS)],
        ];
    }
}
