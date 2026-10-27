<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W3-06 / BR-PRC-004: seed the `approve-price-override` permission and grant it
 * to its configured default roles on existing databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || Role::query()->doesntExist()) {
            return;
        }

        RolePermissions::ensureCatalog();

        Role::query()->each(fn (Role $role) => RolePermissions::grantDefaults($role));
    }

    public function down(): void
    {
        // Granted permissions are left in place; removing them could lock users out.
    }
};
