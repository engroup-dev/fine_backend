<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class EnforceDesktopAppVersion
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip version check for public system version status & health checks
        if ($request->is('api/v1/system/version*') || $request->is('up') || $request->is('sanctum/csrf-cookie')) {
            return $next($request);
        }

        $minVersion = (string) (Cache::get('app:min_desktop_version') ?? config('app.min_desktop_version') ?? '');

        if ($minVersion === '' && config('app.auto_enforce_latest_build')) {
            $latestDb = AppVersion::latest()->value('desktop_version');
            if ($latestDb) {
                $minVersion = (string) $latestDb;
                Cache::forever('app:min_desktop_version', $minVersion);
                Cache::forever('app:latest_desktop_version', $minVersion);
            }
        }

        if ($minVersion === '') {
            return $next($request);
        }

        $clientVersion = $request->header('X-Desktop-Version');

        if (! $clientVersion || version_compare($clientVersion, $minVersion, '<')) {
            $latestVersion = (string) (Cache::get('app:latest_desktop_version') ?? config('app.latest_desktop_version') ?? $minVersion);

            return response()->json([
                'code' => 'FORCE_UPDATE_REQUIRED',
                'message' => 'Desktop app update required. Your current version is obsolete.',
                'message_ar' => 'نسخة تطبيق سطح المكتب قديمة. يرجى التحديث للمتابعة.',
                'current_version' => $clientVersion ?? 'unknown',
                'required_version' => $minVersion,
                'latest_version' => $latestVersion,
                'update_url' => config('app.desktop_update_url', 'https://github.com/engroup-dev/fine-erp/releases/latest'),
            ], 426);
        }

        return $next($request);
    }
}
