<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function loginThrottleUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => Hash::make('Secret-pass1'),
        'is_active' => true,
        'must_change_password' => false,
    ], $attributes));
}

it('throttles an identifier after five failed logins and refuses even the correct password without checking it', function (): void {
    loginThrottleUser(['email' => 'owner@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The provided credentials do not match our records.');
    }

    Hash::spy();

    $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_THROTTLED')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message', 'retry_after'])
        ->assertJsonMissingPath('access_token');

    Hash::shouldNotHaveReceived('check');
});

it('counts the identifier case- and whitespace-insensitively across the login and email fields', function (): void {
    loginThrottleUser(['email' => 'owner@example.com']);

    foreach (['Owner@Example.com', ' owner@example.com', 'OWNER@EXAMPLE.COM', 'owner@example.com '] as $variant) {
        $this->postJson('/api/v1/auth/login', ['login' => $variant, 'password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.COM', 'password' => 'wrong-password'])->assertStatus(422);

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_THROTTLED');
});

it('keeps the identifier throttle per identifier', function (): void {
    loginThrottleUser(['email' => 'first@example.com']);
    loginThrottleUser(['email' => 'second@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'first@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['login' => 'first@example.com', 'password' => 'Secret-pass1'])->assertStatus(429);

    $this->postJson('/api/v1/auth/login', ['login' => 'second@example.com', 'password' => 'Secret-pass1'])->assertOk();
});

it('throttles unknown identifiers exactly like known ones', function (): void {
    loginThrottleUser(['email' => 'known@example.com']);

    foreach (['known@example.com', 'ghost@example.com'] as $identifier) {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['login' => $identifier, 'password' => 'wrong-password'])
                ->assertStatus(422)
                ->assertJsonPath('message', 'The provided credentials do not match our records.');
        }

        $this->postJson('/api/v1/auth/login', ['login' => $identifier, 'password' => 'wrong-password'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'LOGIN_THROTTLED');
    }
});

it('resets the identifier count after a successful login', function (): void {
    loginThrottleUser(['email' => 'owner@example.com']);

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'Secret-pass1'])->assertOk();

    // A fresh budget of five failures after the success, then the sixth attempt is refused.
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_THROTTLED');
});

it('throttles the client IP after twenty failures across identifiers, and a success neither resets nor spends the IP budget', function (): void {
    loginThrottleUser(['email' => 'success@example.com']);
    loginThrottleUser(['email' => 'victim@example.com']);

    // 16 failures over four identifiers (four each, under the per-identifier limit).
    foreach (range(1, 4) as $identifierNumber) {
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/v1/auth/login', ['login' => "guess{$identifierNumber}@example.com", 'password' => 'wrong-password'])
                ->assertStatus(422);
        }
    }

    // A success does not reset or consume the IP count.
    $this->postJson('/api/v1/auth/login', ['login' => 'success@example.com', 'password' => 'Secret-pass1'])->assertOk();
    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'guess5@example.com', 'password' => 'wrong-password'])->assertStatus(422);
    }

    // 20 failures at this IP: a fresh identifier is refused even with the correct password.
    $this->postJson('/api/v1/auth/login', ['login' => 'victim@example.com', 'password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_THROTTLED')
        ->assertHeader('Retry-After');

    // Another client IP is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/auth/login', ['login' => 'victim@example.com', 'password' => 'Secret-pass1'])
        ->assertOk();
});

it('does not spend the identifier budget on requests the IP limit refused before any password check', function (): void {
    loginThrottleUser(['email' => 'owner@example.com']);
    $ipKey = 'login:ip:127.0.0.1';
    RateLimiter::increment($ipKey, 60, 20);

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'Secret-pass1'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'LOGIN_THROTTLED');
    }

    RateLimiter::clear($ipKey);

    $this->postJson('/api/v1/auth/login', ['login' => 'owner@example.com', 'password' => 'Secret-pass1'])->assertOk();
});

it('still refuses a deactivated account with 403 after a correct password, without lifting the throttle for it', function (): void {
    loginThrottleUser(['email' => 'inactive@example.com', 'is_active' => false]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['login' => 'inactive@example.com', 'password' => 'Secret-pass1'])->assertForbidden();
    }

    $this->postJson('/api/v1/auth/login', ['login' => 'inactive@example.com', 'password' => 'Secret-pass1'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'LOGIN_THROTTLED');
});
