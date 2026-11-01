<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);

    $this->blueprint = UnitBlueprint::create([
        'name' => 'Foam Manufactory Blueprint',
        'workflow_set' => ['production_batch' => ['planned', 'closed']],
        'default_role_template' => ['foam-manager' => ['create-foam']],
        'default_inventory_config' => ['warehouse_name' => 'Foam Yard'],
    ]);

    $this->owner = User::factory()->create(['must_change_password' => false]);
    $ownerRole = Role::create(['name' => 'Owner', 'slug' => 'owner']);
    UserRole::create(['user_id' => $this->owner->id, 'role_id' => $ownerRole->id, 'operating_unit_id' => null]);

    $this->asOwner = fn () => $this->actingAs($this->owner);
});

test('owner can list active operating units', function () {
    $a = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'A Unit',
        'unit_type' => 'manufactory',
    ]);
    $b = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'B Unit',
        'unit_type' => 'manufactory',
    ]);

    $rows = ($this->asOwner)()
        ->getJson('/api/v1/operating-units')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('name')->sort()->values()->all())
        ->toBe(['A Unit', 'B Unit']);
});

test('with_trashed=1 includes soft-deleted operating units', function () {
    OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Live',
        'unit_type' => 'manufactory',
    ]);
    $dead = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Deleted',
        'unit_type' => 'manufactory',
    ]);
    $dead->delete();

    $active = ($this->asOwner)()
        ->getJson('/api/v1/operating-units')
        ->assertOk()
        ->json('data');
    expect(collect($active)->pluck('name')->all())->toBe(['Live']);

    $all = ($this->asOwner)()
        ->getJson('/api/v1/operating-units?with_trashed=1')
        ->assertOk()
        ->json('data');
    expect(collect($all)->pluck('name')->sort()->values()->all())
        ->toBe(['Deleted', 'Live']);

    // A deleted unit says so; a live one carries null.
    $byName = collect($all)->keyBy('name');
    expect($byName['Deleted']['deleted_at'])->not->toBeNull()
        ->and($byName['Live']['deleted_at'])->toBeNull();
});

test('owner can soft-delete and restore an operating unit', function () {
    $unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'To Delete',
        'unit_type' => 'manufactory',
    ]);

    ($this->asOwner)()
        ->deleteJson("/api/v1/operating-units/{$unit->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Operating unit deleted successfully.');

    expect(OperatingUnit::find($unit->id))->toBeNull()
        ->and(OperatingUnit::withTrashed()->find($unit->id))->not->toBeNull()
        ->and(OperatingUnit::withTrashed()->find($unit->id)->deleted_at)->not->toBeNull();

    ($this->asOwner)()
        ->postJson("/api/v1/operating-units/{$unit->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $unit->id)
        ->assertJsonPath('data.name', 'To Delete');

    expect(OperatingUnit::find($unit->id))->not->toBeNull()
        ->and(OperatingUnit::find($unit->id)->deleted_at)->toBeNull();
});

test('restore on an already-active unit is idempotent', function () {
    $unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Active',
        'unit_type' => 'manufactory',
    ]);

    ($this->asOwner)()
        ->postJson("/api/v1/operating-units/{$unit->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $unit->id);
});

test('owner can update name, status, and manager of an operating unit', function () {
    $manager = User::factory()->create();
    $unit = OperatingUnit::create([
        'company_id' => $this->company->id,
        'blueprint_id' => $this->blueprint->id,
        'name' => 'Original',
        'unit_type' => 'manufactory',
        'status' => 'active',
    ]);

    ($this->asOwner)()
        ->putJson("/api/v1/operating-units/{$unit->id}", [
            'name' => 'Renamed',
            'status' => 'inactive',
            'manager_user_id' => $manager->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.manager_user_id', $manager->id);
});
