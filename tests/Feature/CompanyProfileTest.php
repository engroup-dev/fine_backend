<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\OperatingUnit;
use App\Models\Role;
use App\Models\UnitBlueprint;
use App\Models\User;
use App\Models\UserRole;
use App\Observers\AuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * W6-02: company profile — any signed-in user reads it, a company-wide owner or
 * admin changes it (not an admin of a single unit), the logo is a validated image data URL of at most 200 KB, the
 * change is audit-logged without the logo bytes, and the HR / fiscal settings
 * on the same row are untouched.
 */
beforeEach(function () {
    $this->company = Company::create(['name' => 'Fine Foam Mfg', 'default_currency' => 'LYD']);
    $blueprint = UnitBlueprint::create([
        'name' => 'Store', 'workflow_set' => [], 'default_role_template' => [], 'default_inventory_config' => [],
    ]);
    $this->unit = OperatingUnit::create([
        'company_id' => $this->company->id, 'blueprint_id' => $blueprint->id, 'name' => 'Showroom', 'unit_type' => 'store',
    ]);

    $makeUser = function (string $slug, ?string $unitId): User {
        $user = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'operating_unit_id' => $unitId]);

        return $user;
    };

    $this->owner = $makeUser('owner', null);
    $this->admin = $makeUser('admin', null);
    $this->cashier = $makeUser('cashier', $this->unit->id);

    // A real 1x1 PNG.
    $this->png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
});

test('any signed-in user reads the company profile', function () {
    $this->company->update(['legal_name' => 'شركة فاين للإسفنج', 'tax_number' => 'TX-1', 'logo' => $this->png]);

    $this->actingAs($this->cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('id', $this->company->id)
        ->assertJsonPath('name', 'Fine Foam Mfg')
        ->assertJsonPath('legal_name', 'شركة فاين للإسفنج')
        ->assertJsonPath('tax_number', 'TX-1')
        ->assertJsonPath('logo', $this->png)
        ->assertJsonPath('receipt_footer', null);
});

test('the owner updates the profile and the change is audited without the logo bytes', function () {
    AuditLog::query()->delete();

    $this->actingAs($this->owner)
        ->putJson('/api/v1/company', [
            'name' => 'Fine Foam',
            'legal_name' => 'Fine Foam Manufacturing Co.',
            'tax_number' => '123456',
            'commercial_register' => 'CR-778',
            'address' => 'Misrata, Libya',
            'phone' => '+218 91 000 0000',
            'email' => 'info@fine.example',
            'logo' => $this->png,
            'receipt_footer' => 'شكراً لزيارتكم',
            'invoice_terms' => 'Goods sold are not returnable after 7 days.',
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Fine Foam')
        ->assertJsonPath('commercial_register', 'CR-778')
        ->assertJsonPath('logo', $this->png)
        ->assertJsonPath('receipt_footer', 'شكراً لزيارتكم');

    $company = $this->company->fresh();
    expect($company->email)->toBe('info@fine.example')
        ->and($company->logo)->toBe($this->png);

    $log = AuditLog::where('table_name', 'companies')->where('record_id', $company->id)->where('action', 'updated')->sole();
    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->new_values['tax_number'])->toBe('123456')
        ->and($log->old_values['logo'])->toBeNull()
        ->and($log->new_values['logo'])->toBe(AuditObserver::REDACTED)
        ->and(json_encode($log->new_values))->not->toContain('iVBORw0KGgo');
});

test('an admin may update, a cashier may not', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/company', ['phone' => '0910000000'])
        ->assertOk()
        ->assertJsonPath('phone', '0910000000');

    $this->actingAs($this->cashier)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->putJson('/api/v1/company', ['phone' => '0920000000'])
        ->assertForbidden();

    expect($this->company->fresh()->phone)->toBe('0910000000');
});

test('an admin or owner of a single unit may not change the company-wide profile', function (string $slug) {
    $this->company->update(['legal_name' => 'Fine Foam Co.']);

    $unitUser = User::factory()->create(['must_change_password' => false]);
    $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
    UserRole::create(['user_id' => $unitUser->id, 'role_id' => $role->id, 'operating_unit_id' => $this->unit->id]);

    $this->actingAs($unitUser)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->putJson('/api/v1/company', ['legal_name' => 'Hijacked', 'tax_number' => '999'])
        ->assertForbidden()
        ->assertJsonPath('code', 'COMPANY_PROFILE_FORBIDDEN');

    $company = $this->company->fresh();
    expect($company->legal_name)->toBe('Fine Foam Co.')
        ->and($company->tax_number)->toBeNull();

    // Reading stays open to them.
    $this->actingAs($unitUser)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('legal_name', 'Fine Foam Co.');
})->with(['admin', 'owner']);

test('a partial update keeps other fields and the logo can be cleared', function () {
    $this->company->update(['legal_name' => 'Keep Me', 'logo' => $this->png]);

    AuditLog::query()->delete();

    $this->actingAs($this->owner)
        ->putJson('/api/v1/company', ['logo' => null])
        ->assertOk()
        ->assertJsonPath('logo', null)
        ->assertJsonPath('legal_name', 'Keep Me');

    // The audit row shows the logo was removed (not replaced) without its bytes.
    $log = AuditLog::where('table_name', 'companies')->where('record_id', $this->company->id)->where('action', 'updated')->sole();
    expect($log->old_values['logo'])->toBe(AuditObserver::REDACTED)
        ->and($log->new_values['logo'])->toBeNull()
        ->and(json_encode($log->old_values))->not->toContain('iVBORw0KGgo');
});

test('a PNG, JPEG or WebP logo is accepted and stored as sent', function (string $logo) {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/company', ['logo' => $logo])
        ->assertOk()
        ->assertJsonPath('logo', $logo);

    expect($this->company->fresh()->logo)->toBe($logo);
})->with([
    // Real 1x1 images.
    'png' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'jpeg' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDkKKKK8U/TD//Z',
    'webp' => 'data:image/webp;base64,UklGRjoAAABXRUJQVlA4IC4AAACwAQCdASoBAAEAAUAmJaACdLoABDAAAP7x3I/4DdfFtMv/vYL/3YL/3YL/WwAA',
]);

test('the logo must be a real raster image data URL of at most 200 KB', function (string $logo) {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/company', ['logo' => $logo])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('logo');

    expect($this->company->fresh()->logo)->toBeNull();
})->with([
    'svg (can carry script)' => 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'not a data url' => 'https://example.com/logo.png',
    'png label on non-image bytes' => 'data:image/png;base64,'.base64_encode('just some text, not an image'),
    'jpeg label on png bytes' => 'data:image/jpeg;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'broken base64' => 'data:image/png;base64,@@@@',
    'over 200 KB' => 'data:image/png;base64,'.str_repeat('A', 210 * 1024),
]);

test('a rejected logo is not echoed back in the error', function () {
    $marker = base64_encode(str_repeat('SECRETBYTES', 20));

    $response = $this->actingAs($this->owner)
        ->putJson('/api/v1/company', ['logo' => 'data:image/png;base64,'.$marker])
        ->assertUnprocessable();

    expect($response->getContent())->not->toContain($marker);
});

test('other fields are validated', function () {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/company', ['name' => '', 'email' => 'not-an-email', 'tax_number' => str_repeat('9', 51)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'tax_number']);
});

test('the profile update leaves HR and fiscal settings alone', function () {
    $this->actingAs($this->owner)->putJson('/api/v1/hr-settings', ['annual_leave_days' => 21])->assertOk();
    $this->actingAs($this->owner)->putJson('/api/v1/company', ['legal_name' => 'Fine Foam Co.'])->assertOk();

    $this->actingAs($this->owner)->getJson('/api/v1/hr-settings')
        ->assertOk()
        ->assertJsonPath('annual_leave_days', 21)
        ->assertJsonPath('overtime_multiplier', 1.5);

    expect($this->company->fresh()->legal_name)->toBe('Fine Foam Co.');
});

test('a profile change is audited without a unit, even when a unit is selected', function () {
    AuditLog::query()->delete();

    $this->actingAs($this->owner)
        ->withHeader('X-Operating-Unit-ID', $this->unit->id)
        ->putJson('/api/v1/company', ['legal_name' => 'Fine Foam Co.'])
        ->assertOk();

    $log = AuditLog::where('table_name', 'companies')->where('action', 'updated')->sole();
    expect($log->operating_unit_id)->toBeNull();
});
