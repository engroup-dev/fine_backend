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
use Illuminate\Support\Str;

final class AuthController extends Controller
{
    /** Verify-password attempts allowed per user per window (W6-05 idle lock); a success resets the count. */
    private const int VERIFY_MAX_PER_USER = 5;

    /** Failed verify-password attempts allowed per client IP per window; higher than per user, and one user can spend at most VERIFY_MAX_PER_USER of it. */
    private const int VERIFY_MAX_PER_IP = 20;

    private const int VERIFY_DECAY_SECONDS = 60;

    /** Login attempts allowed per normalized identifier (trimmed, lowercased email/phone) per window; a successful login resets the count. Unknown identifiers are counted exactly like known ones. */
    private const int LOGIN_MAX_PER_IDENTIFIER = 5;

    /** Failed login attempts allowed per client IP per window; higher than per identifier (offices share one address), and one identifier can spend at most LOGIN_MAX_PER_IDENTIFIER of it. */
    private const int LOGIN_MAX_PER_IP = 20;

    private const int LOGIN_DECAY_SECONDS = 60;

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

        // Counted before the user lookup, keyed by a hash of the normalized identifier, so unknown
        // identifiers hit the same limits as real accounts and arbitrary input never becomes a raw cache key.
        $identifierKey = 'login:identifier:'.hash('sha256', Str::lower(trim($identifier)));
        $ipKey = 'login:ip:'.$request->ip();

        $throttled = $this->countPasswordAttempt(
            $identifierKey, self::LOGIN_MAX_PER_IDENTIFIER,
            $ipKey, self::LOGIN_MAX_PER_IP,
            self::LOGIN_DECAY_SECONDS,
            'Too many failed login attempts. Please wait before trying again.',
            'LOGIN_THROTTLED',
        );

        if ($throttled !== null) {
            return $throttled;
        }

        $user = User::where(function ($query) use ($identifier) {
            $query->where('email', $identifier)
                ->orWhere('phone', $identifier);
        })->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The provided credentials do not match our records.',
            ], 422);
        }

        // A correct password is not a guess: give its IP slot back.
        RateLimiter::decrement($ipKey, self::LOGIN_DECAY_SECONDS);

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        RateLimiter::clear($identifierKey);

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
     *
     * The current-password check spends the same per-user and per-IP budget as verify-password
     * (W6-05), so a locked session's token cannot guess the password here instead.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        [$userKey, $ipKey] = $this->verifyPasswordKeys($request);

        $throttled = $this->countVerifyPasswordAttempt($userKey, $ipKey);

        if ($throttled !== null) {
            return $throttled;
        }

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'The provided current password does not match our records.',
                'code' => 'CURRENT_PASSWORD_INVALID',
            ], 422);
        }

        // A correct password is not a guess: give its IP slot back and reset the user's count.
        RateLimiter::decrement($ipKey, self::VERIFY_DECAY_SECONDS);
        RateLimiter::clear($userKey);

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
        [$userKey, $ipKey] = $this->verifyPasswordKeys($request);

        $throttled = $this->countVerifyPasswordAttempt($userKey, $ipKey);

        if ($throttled !== null) {
            return $throttled;
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

    /**
     * The verify-password budget keys, shared by verify-password and change-password.
     *
     * @return array{0: string, 1: string}
     */
    private function verifyPasswordKeys(Request $request): array
    {
        return [
            'verify-password:user:'.$request->user()->id,
            'verify-password:ip:'.$request->ip(),
        ];
    }

    /**
     * Count one signed-in password check against the shared verify-password budget.
     */
    private function countVerifyPasswordAttempt(string $userKey, string $ipKey): ?JsonResponse
    {
        return $this->countPasswordAttempt(
            $userKey, self::VERIFY_MAX_PER_USER,
            $ipKey, self::VERIFY_MAX_PER_IP,
            self::VERIFY_DECAY_SECONDS,
            'Too many failed password attempts. Please wait before trying again.',
            'PASSWORD_VERIFY_THROTTLED',
        );
    }

    /**
     * Count one password attempt per subject (user or login identifier) and per client IP, before
     * any hash check, and return the 429 response if either budget is spent (null otherwise).
     *
     * Counting first is atomic, so parallel requests cannot exceed the limit. A throttled subject's
     * retries never reach the IP counter, so one subject cannot spend the shared IP budget beyond its
     * own limit; a request the IP limit refuses gives its subject slot back, so an IP throttle caused
     * by others never extends this subject's own lockout. Callers give the IP slot back and clear the
     * subject count once the password proves correct.
     */
    private function countPasswordAttempt(
        string $subjectKey,
        int $maxPerSubject,
        string $ipKey,
        int $maxPerIp,
        int $decaySeconds,
        string $message,
        string $code,
    ): ?JsonResponse {
        if (RateLimiter::hit($subjectKey, $decaySeconds) > $maxPerSubject) {
            return $this->throttledResponse($subjectKey, $message, $code);
        }

        if (RateLimiter::hit($ipKey, $decaySeconds) > $maxPerIp) {
            RateLimiter::decrement($subjectKey, $decaySeconds);

            return $this->throttledResponse($ipKey, $message, $code);
        }

        return null;
    }

    private function throttledResponse(string $key, string $message, string $code): JsonResponse
    {
        $retryAfter = max(RateLimiter::availableIn($key), 1);

        return response()->json([
            'message' => $message,
            'code' => $code,
            'retry_after' => $retryAfter,
        ], 429, ['Retry-After' => (string) $retryAfter]);
    }
}
