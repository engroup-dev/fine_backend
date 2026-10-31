<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Rules\LogoDataUrl;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * W6-02: the company profile printed on document headers and receipts
 * (names, tax and commercial register numbers, contact details, logo,
 * receipt footer and invoice terms). Any signed-in user reads it, and every
 * change is audit-logged by the Company observer.
 *
 * The profile prints on every unit's documents, so only a company-wide owner
 * or admin changes it: a user_roles row with slug owner or admin and no
 * operating unit. The route's require.role:owner,admin is not enough on its
 * own, since RequireRole passes a user who is admin in ANY unit; an admin of a
 * single unit is refused here with 403 COMPANY_PROFILE_FORBIDDEN (the same
 * rule as AuditLogController::scopeToUnit for company-wide readers).
 */
final class CompanyController extends Controller
{
    /** Roles that may change the profile, when held with no operating unit. */
    private const WRITE_ROLES = ['owner', 'admin'];

    public function show(): JsonResponse
    {
        return response()->json($this->payload(Company::query()->firstOrFail()));
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureCompanyWideWriter($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'logo' => ['sometimes', 'nullable', new LogoDataUrl],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'invoice_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $company = DB::transaction(function () use ($validated): Company {
            $company = Company::query()->lockForUpdate()->firstOrFail();
            $company->update($validated);

            return $company;
        });

        return response()->json($this->payload($company));
    }

    private function ensureCompanyWideWriter(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && $user->roles()
            ->whereNull('user_roles.operating_unit_id')
            ->whereIn('roles.slug', self::WRITE_ROLES)
            ->exists()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The company profile prints on every unit\'s documents; changing it needs a company-wide owner or admin role.',
            'code' => 'COMPANY_PROFILE_FORBIDDEN',
        ], 403));
    }

    /**
     * @return array{id: string, name: string, legal_name: ?string, tax_number: ?string, commercial_register: ?string, address: ?string, phone: ?string, email: ?string, logo: ?string, receipt_footer: ?string, invoice_terms: ?string, updated_at: ?string}
     */
    private function payload(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'tax_number' => $company->tax_number,
            'commercial_register' => $company->commercial_register,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'logo' => $company->logo,
            'receipt_footer' => $company->receipt_footer,
            'invoice_terms' => $company->invoice_terms,
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}
