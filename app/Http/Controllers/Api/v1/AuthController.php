<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    /** Verify-password attempts allowed per user per window (W6-05 idle lock); a success resets the count. */
    private const int VERIFY_MAX_PER_USER = 5;

    /** Failed verify-password attempts allowed per client IP per window; higher than per user, and one user can spend at most VERIFY_MAX_PER_USER of it. */
    private const int VERIFY_MAX_PER_IP = 20;

    private const int VERIFY_DECAY_SECONDS = 60;

    /**
     * Authenticate user credentials and return a token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login' => 'required_without:email|string',
            'email' => 'required_without:login|string',
            'password' => 'required|string',
        ]);

        $identifier = (string) ($request->input('login') ?? $request->input('email'));

        $user = User::where(function ($query) use ($identifier) {
            $query->where('email', $identifier)
                ->orWhere('phone', $identifier);
        })->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided credentials do not match our records.',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        $user->load(['roles.permissions']);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Revoke the current user's token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Retrieve the authenticated user profile.
     */
    public function me(Request $request): UserResource
    {
        $user = $request->user()->load(['roles.permissions']);

        return new UserResource($user);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'The provided current password does not match our records.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Re-check the signed-in user's password (W6-05 idle lock screen).
     *
     * Only confirms the password: no token is issued, revoked or changed. Each attempt is counted
     * (atomically, before the hash check, so parallel requests cannot exceed the limit) per user,
     * and per client IP for attempts the per-user limit let through; a correct password does not
     * consume the IP budget, and a request the IP limit refuses does not consume the user budget. While throttled, even a correct password is refused. A deactivated
     * account is refused like login refuses it.
     */
    public function verifyPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();
        $userKey = 'verify-password:user:'.$user->id;
        $ipKey = 'verify-password:ip:'.$request->ip();

        // Count first: a throttled user's retries never reach the IP counter, so one user cannot
        // spend the shared IP budget beyond their own per-user limit.
        if (RateLimiter::hit($userKey, self::VERIFY_DECAY_SECONDS) > self::VERIFY_MAX_PER_USER) {
            return $this->verifyThrottled($userKey);
        }

        if (RateLimiter::hit($ipKey, self::VERIFY_DECAY_SECONDS) > self::VERIFY_MAX_PER_IP) {
            // Refused before any hash check: give the user's slot back, so an IP throttle caused by
            // others never extends this user's own lockout.
            RateLimiter::decrement($userKey, self::VERIFY_DECAY_SECONDS);

            return $this->verifyThrottled($ipKey);
        }

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'The provided password is incorrect.',
                'code' => 'PASSWORD_INVALID',
            ], 422);
        }

        // A correct password is not a guess: give its IP slot back.
        RateLimiter::decrement($ipKey, self::VERIFY_DECAY_SECONDS);

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], 403);
        }

        RateLimiter::clear($userKey);

        return response()->json([
            'message' => 'Password verified.',
        ]);
    }

    private function verifyThrottled(string $key): JsonResponse
    {
        $retryAfter = max(RateLimiter::availableIn($key), 1);

        return response()->json([
            'message' => 'Too many failed password attempts. Please wait before trying again.',
            'code' => 'PASSWORD_VERIFY_THROTTLED',
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
