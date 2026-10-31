<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * W5-07: gives every supplier without an entity an organization entity,
     * a vendor role in the supplier's unit (live suppliers only) and a
     * primary contact built from the free-text contact and address.
     *
     * The free-text contact becomes the email when it looks like one, the
     * phone when it is only digits and phone punctuation, and the contact
     * name otherwise. Query-builder writes on purpose: a backfill is not an
     * audited user action.
     */
    public function up(): void
    {
        DB::table('suppliers')
            ->whereNull('entity_id')
            ->orderBy('id')
            ->get(['id', 'operating_unit_id', 'name', 'contact', 'address', 'created_at', 'deleted_at'])
            ->each(function (object $supplier): void {
                DB::transaction(function () use ($supplier): void {
                    $now = now();
                    $entityId = (string) Str::uuid();

                    DB::table('entities')->insert([
                        'id' => $entityId,
                        'name' => $supplier->name,
                        'entity_type' => 'organization',
                        'tax_number' => null,
                        'user_id' => null,
                        'is_active' => true,
                        'record_version' => 1,
                        'created_at' => $supplier->created_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    if ($supplier->deleted_at === null) {
                        DB::table('entity_roles')->insert([
                            'id' => (string) Str::uuid(),
                            'entity_id' => $entityId,
                            'role_type' => 'vendor',
                            'operating_unit_id' => $supplier->operating_unit_id,
                            'created_at' => $now,
                        ]);
                    }

                    $contact = trim((string) $supplier->contact);
                    $address = trim((string) $supplier->address);

                    if ($contact !== '' || $address !== '') {
                        $isEmail = $contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
                        $isPhone = ! $isEmail && $contact !== '' && mb_strlen($contact) <= 50
                            && preg_match('/^\+?[0-9\s\-().\/]{5,}$/', $contact) === 1;

                        DB::table('entity_contacts')->insert([
                            'id' => (string) Str::uuid(),
                            'entity_id' => $entityId,
                            'contact_name' => ($contact !== '' && ! $isEmail && ! $isPhone) ? $contact : null,
                            'email' => $isEmail ? $contact : null,
                            'phone' => $isPhone ? $contact : null,
                            'address' => $address !== '' ? $address : null,
                            'city' => null,
                            'country' => 'LY',
                            'is_primary' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('suppliers')->where('id', $supplier->id)->update(['entity_id' => $entityId]);
                });
            });
    }

    /**
     * The entities stay: they may since have gained other roles or be linked
     * by new suppliers. Only the backfilled links are not tracked, so there is
     * nothing safe to undo.
     */
    public function down(): void
    {
        //
    }
};
