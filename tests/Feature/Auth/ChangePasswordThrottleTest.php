<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function changePasswordThrottleUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('Secret-pass1'),
        'is_active' => true,
        'must_change_password' => false,
    ], $attributes));
}

/**
 * @return array{current_password: string, password: string, password_confirmation: string}
 */
function changePasswordPayload(string $currentPassword): array
{
    return [
        'current_password' => $currentPassword,
        'password' => 'Brand-new-pass2',
        'password_confirmation' => 'Brand-new-pass2',
    ];
}

it('refuses a wrong current password with the existing 422 message and a CURRENT_PASSWORD_INVALID code', function (): void {
    Sanctum::actingAs(changePasswordThrottleUser());

    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('wrong-password'))
        ->assertStatus(422)
        ->assertJsonPath('message', 'The provided current password does not match our records.')
        ->assertJsonPath('code', 'CURRENT_PASSWORD_INVALID');
});

it('refuses a correct current password after five failed verify-password attempts', function (): void {
    $user = changePasswordThrottleUser();
    Sanctum::actingAs($user);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('Secret-pass1'))
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['retry_after']);

    expect(Hash::check('Secret-pass1', $user->fresh()->password))->toBeTrue();
});

it('throttles verify-password after five failed change-password attempts', function (): void {
    Sanctum::actingAs(changePasswordThrottleUser());

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/change-password', changePasswordPayload('wrong-password'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENT_PASSWORD_INVALID');
    }

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED');
});

it('refuses even the correct current password while throttled, without checking it or changing the password', function (): void {
    $user = changePasswordThrottleUser();
    Sanctum::actingAs($user);

    foreach (range(1, 2) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/change-password', changePasswordPayload('wrong-password'))->assertStatus(422);
    }

    Hash::spy();

    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('Secret-pass1'))
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED');

    Hash::shouldNotHaveReceived('check');
    Hash::shouldNotHaveReceived('make');
    expect($user->fresh()->password)->toBe($user->password);
});

it('leaves no stray counts after a successful change: the user count resets and the IP keeps only real failures', function (): void {
    $user = changePasswordThrottleUser();
    Sanctum::actingAs($user);

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('Secret-pass1'))->assertOk();

    expect(RateLimiter::attempts('verify-password:user:'.$user->id))->toBe(0)
        ->and(RateLimiter::attempts('verify-password:ip:127.0.0.1'))->toBe(4);
});

it('shares the per-IP verify-password budget across users and both endpoints', function (): void {
    $users = collect(range(1, 6))->map(fn () => changePasswordThrottleUser());

    // 20 failures at this IP: two users on verify-password, three on change-password (four each).
    foreach ($users->take(5) as $index => $user) {
        Sanctum::actingAs($user);
        foreach (range(1, 4) as $attempt) {
            $index < 2
                ? $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422)
                : $this->postJson('/api/v1/auth/change-password', changePasswordPayload('wrong-password'))->assertStatus(422);
        }
    }

    Sanctum::actingAs($users[5]);
    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('Secret-pass1'))
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED');

    expect(RateLimiter::attempts('verify-password:user:'.$users[5]->id))->toBe(0);
});

it('does not count requests that fail validation', function (): void {
    $user = changePasswordThrottleUser();
    Sanctum::actingAs($user);

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    $this->postJson('/api/v1/auth/change-password', changePasswordPayload('Secret-pass1'))->assertOk();
});
