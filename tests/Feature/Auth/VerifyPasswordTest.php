<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function verifyPasswordUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('Secret-pass1'),
        'is_active' => true,
        'must_change_password' => false,
    ], $attributes));
}

it('confirms the signed-in user password without issuing or revoking a token', function (): void {
    $user = verifyPasswordUser();
    $token = $user->createToken('auth_token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertOk()
        ->assertJsonMissingPath('access_token');

    expect(PersonalAccessToken::count())->toBe(1);

    // The same token still authenticates afterwards.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses a wrong password with 422 PASSWORD_INVALID', function (): void {
    Sanctum::actingAs(verifyPasswordUser());

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PASSWORD_INVALID');
});

it('requires a password', function (): void {
    Sanctum::actingAs(verifyPasswordUser());

    $this->postJson('/api/v1/auth/verify-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('throttles after five failed attempts and refuses even the correct password while throttled', function (): void {
    Sanctum::actingAs(verifyPasswordUser());

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['retry_after']);
});

it('keeps the throttle per user', function (): void {
    $first = verifyPasswordUser();
    $second = verifyPasswordUser();

    Sanctum::actingAs($first);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertStatus(429);

    Sanctum::actingAs($second);
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
});

it('resets the per-user failure count after a successful verification', function (): void {
    Sanctum::actingAs(verifyPasswordUser());

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
});

it('rejects unauthenticated requests with 401', function (): void {
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertUnauthorized();
});

it('works for a user who must change their password, like the other auth routes, and leaves the flag alone', function (): void {
    $user = verifyPasswordUser(['must_change_password' => true]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PASSWORD_INVALID');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('refuses a deactivated account with 403 ACCOUNT_DEACTIVATED even with the correct password', function (): void {
    $user = verifyPasswordUser(['is_active' => false]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PASSWORD_INVALID');
});

it('counts the attempt before checking the password, so an exhausted budget refuses without a hash check', function (): void {
    $user = verifyPasswordUser();
    Sanctum::actingAs($user);
    RateLimiter::increment('verify-password:user:'.$user->id, 60, 5);
    Hash::spy();

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED');

    Hash::shouldNotHaveReceived('check');
});

it('has already counted the attempt while the password is being checked (parallel requests cannot overrun the limit)', function (): void {
    $user = verifyPasswordUser();
    Sanctum::actingAs($user);
    $userKey = 'verify-password:user:'.$user->id;
    $countedDuringCheck = null;

    Hash::shouldReceive('check')->once()->andReturnUsing(function () use ($userKey, &$countedDuringCheck): bool {
        $countedDuringCheck = RateLimiter::attempts($userKey);

        return false;
    });

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);

    expect($countedDuringCheck)->toBe(1);
});

it('throttles the client IP after twenty failures across users, and a success neither resets nor spends the IP budget', function (): void {
    $users = collect(range(1, 6))->map(fn () => verifyPasswordUser());

    // 16 failures from four users (four each, under the per-user limit).
    foreach ($users->take(4) as $user) {
        Sanctum::actingAs($user);
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
        }
    }

    // A success from the fifth user does not reset or consume the IP count.
    Sanctum::actingAs($users[4]);
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password'])->assertStatus(422);
    }

    // 20 failures at this IP: a sixth user is refused even with the correct password.
    Sanctum::actingAs($users[5]);
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED')
        ->assertHeader('Retry-After');
});

it('does not let one user spend the shared IP budget beyond their own limit', function (): void {
    $attacker = verifyPasswordUser();
    $colleague = verifyPasswordUser();

    Sanctum::actingAs($attacker);
    foreach (range(1, 30) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'wrong-password']);
    }
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertStatus(429);

    Sanctum::actingAs($colleague);
    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
});

it('does not spend the user budget on requests the IP limit refused before any password check', function (): void {
    $user = verifyPasswordUser();
    Sanctum::actingAs($user);
    $ipKey = 'verify-password:ip:127.0.0.1';
    RateLimiter::increment($ipKey, 60, 20);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'PASSWORD_VERIFY_THROTTLED');
    }

    expect(RateLimiter::attempts('verify-password:user:'.$user->id))->toBe(0);

    RateLimiter::clear($ipKey);

    $this->postJson('/api/v1/auth/verify-password', ['password' => 'Secret-pass1'])->assertOk();
});
