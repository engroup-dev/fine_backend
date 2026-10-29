<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class SystemVersionController extends Controller
{
    /**
     * Record the client desktop version and return current backend version.
     */
    public function record(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'desktop_version' => 'required|string|max:50',
            'platform' => 'nullable|string|max:50',
        ]);

        $backendVersion = (string) config('app.version', '1.0.0');
        $user = $request->user('sanctum');
        $operatingUnitId = $request->header('X-Operating-Unit-ID');

        $record = AppVersion::create([
            'desktop_version' => $validated['desktop_version'],
            'backend_version' => $backendVersion,
            'user_id' => $user?->id,
            'operating_unit_id' => $operatingUnitId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'platform' => $validated['platform'] ?? null,
        ]);

        // If auto-enforcing latest build is enabled, bump required version when a higher version reports
        if (config('app.auto_enforce_latest_build')) {
            $currentEnforced = (string) (Cache::get('app:min_desktop_version') ?? config('app.min_desktop_version') ?? '');
            if ($currentEnforced === '' || version_compare($validated['desktop_version'], $currentEnforced, '>')) {
                Cache::forever('app:min_desktop_version', $validated['desktop_version']);
                Cache::forever('app:latest_desktop_version', $validated['desktop_version']);
            }
        }

        $minVersion = (string) (Cache::get('app:min_desktop_version') ?? config('app.min_desktop_version') ?? '');
        $latestVersion = (string) (Cache::get('app:latest_desktop_version') ?? config('app.latest_desktop_version') ?? $minVersion);
        $isUpdateRequired = $minVersion !== '' && version_compare($validated['desktop_version'], $minVersion, '<');

        return response()->json([
            'id' => $record->id,
            'desktop_version' => $record->desktop_version,
            'backend_version' => $record->backend_version,
            'platform' => $record->platform,
            'recorded_at' => $record->created_at?->toIso8601String(),
            'min_desktop_version' => $minVersion ?: null,
            'latest_desktop_version' => $latestVersion ?: null,
            'is_update_required' => $isUpdateRequired,
            'update_url' => config('app.desktop_update_url', 'https://github.com/engroup-dev/fine-erp/releases/latest'),
        ], 201);
    }

    /**
     * Get the current backend and required desktop versions without recording a new log entry.
     */
    public function current(Request $request): JsonResponse
    {
        $clientVersion = $request->header('X-Desktop-Version') ?? $request->query('client_version');
        $minVersion = (string) (Cache::get('app:min_desktop_version') ?? config('app.min_desktop_version') ?? '');

        if ($minVersion === '' && config('app.auto_enforce_latest_build')) {
            $latestDb = AppVersion::latest()->value('desktop_version');
            if ($latestDb) {
                $minVersion = (string) $latestDb;
                Cache::forever('app:min_desktop_version', $minVersion);
                Cache::forever('app:latest_desktop_version', $minVersion);
            }
        }

        $latestVersion = (string) (Cache::get('app:latest_desktop_version') ?? config('app.latest_desktop_version') ?? $minVersion);
        $isUpdateRequired = $minVersion !== '' && $clientVersion && version_compare((string) $clientVersion, $minVersion, '<');

        return response()->json([
            'backend_version' => (string) config('app.version', '1.0.0'),
            'min_desktop_version' => $minVersion ?: null,
            'latest_desktop_version' => $latestVersion ?: null,
            'is_update_required' => (bool) $isUpdateRequired,
            'update_url' => config('app.desktop_update_url', 'https://github.com/engroup-dev/fine-erp/releases/latest'),
        ]);
    }

    /**
     * List recorded versions (Owner / Admin oversight).
     */
    public function index(Request $request): JsonResponse
    {
        $versions = AppVersion::with([
            'user:id,name,email',
            'operatingUnit:id,name',
        ])
            ->latest()
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($versions);
    }
}
