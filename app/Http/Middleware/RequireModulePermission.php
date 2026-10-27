<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module-level permission check for a route group: reads (GET/HEAD) need the
 * view permission, everything else the write permission. Usage:
 * `require.module:view-foam,edit-foam`. Either slot may list alternatives
 * separated by `|` (`view-sales|create-sales-returns`): holding any one of
 * them is enough. Runs after require.role, which still bounds who can reach
 * the group at all.
 */
final class RequireModulePermission
{
    public function handle(Request $request, Closure $next, string $viewPermission, ?string $writePermission = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED'], 401);
        }

        // Same bypass as RequireRole: owner and admin reach every module.
        if ($user->hasRole('owner') || $user->hasRole('admin')) {
            return $next($request);
        }

        $permission = in_array($request->method(), ['GET', 'HEAD'], true)
            ? $viewPermission
            : ($writePermission ?? $viewPermission);

        $alternatives = explode('|', $permission);

        foreach ($alternatives as $alternative) {
            if ($user->hasPermission($alternative)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You lack the necessary permission for this resource.',
            'code' => 'ACCESS_DENIED',
            'required_permissions' => $alternatives,
        ], 403);
    }
}
