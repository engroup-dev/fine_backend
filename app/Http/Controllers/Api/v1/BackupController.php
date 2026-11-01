<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\BackupStatus;
use App\Enums\BackupTrigger;
use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W6-03: database backups for the owner — list, take one now, download one
 * (streamed; the desktop fetches it as a blob with its Bearer token) and
 * delete one. There is no restore endpoint: restoring replaces the live
 * database, so it is done on the server from a downloaded file (the desktop
 * page shows the steps).
 *
 * A backup holds every unit's records, so only a company-wide owner (an owner
 * role with no operating unit) gets in. The route's require.role:owner is not
 * enough on its own: RequireRole also lets any admin through. Everyone else
 * gets 403 BACKUP_FORBIDDEN.
 */
final class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        $rows = Backup::query()
            ->with('user:id,name')
            ->latest()
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (Backup $backup): array => $this->payload($backup))->all()]);
    }

    /**
     * Take a backup now, synchronously (bounded by config('backups.timeout')
     * for the dump itself). A failed dump is kept as a failed row and answered
     * with 422 BACKUP_FAILED.
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        set_time_limit((int) config('backups.timeout', 900) + 120);

        $backup = $this->backups->run(BackupTrigger::Manual, $request->user());
        $backup->load('user:id,name');

        if ($backup->status !== BackupStatus::Completed) {
            return response()->json([
                'message' => 'The backup failed: '.$backup->error,
                'code' => 'BACKUP_FAILED',
                'backup' => $this->payload($backup),
            ], 422);
        }

        return response()->json(['data' => $this->payload($backup)], 201);
    }

    /**
     * Stream the backup file. The file is found through the row (by id), never
     * from a path in the request.
     */
    public function download(Request $request, Backup $backup): StreamedResponse
    {
        $this->ensureOwner($request);

        if ($backup->status !== BackupStatus::Completed) {
            throw new BackupException('Only a completed backup can be downloaded.', 'BACKUP_NOT_COMPLETED', ['backup_id' => $backup->id]);
        }

        $this->backups->guardFileName($backup->file);
        $disk = $this->backups->disk();

        if (! $disk->exists($backup->file)) {
            throw new BackupException('The backup file is missing from the backups disk.', 'BACKUP_FILE_MISSING', ['backup_id' => $backup->id]);
        }

        return $disk->download($backup->file, $backup->file, [
            'Content-Type' => 'application/gzip',
            'X-Backup-Checksum-Sha256' => (string) $backup->checksum,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        $this->ensureOwner($request);

        $this->backups->delete($backup);

        return response()->json(null, 204);
    }

    private function ensureOwner(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && $user->roles()
            ->whereNull('user_roles.operating_unit_id')
            ->where('roles.slug', 'owner')
            ->exists()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Backups hold every unit\'s records; only the company-wide owner manages them.',
            'code' => 'BACKUP_FORBIDDEN',
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'file' => $backup->file,
            'size_bytes' => $backup->size_bytes,
            'checksum' => $backup->checksum,
            'driver' => $backup->driver,
            'status' => $backup->status->value,
            'status_label' => $backup->status->label(),
            'error' => $backup->error,
            'trigger' => $backup->trigger->value,
            'trigger_label' => $backup->trigger->label(),
            'user' => $backup->user === null ? null : ['id' => $backup->user->id, 'name' => $backup->user->name],
            'started_at' => $backup->started_at?->toIso8601String(),
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'verified_at' => $backup->verified_at?->toIso8601String(),
            'verify_error' => $backup->verify_error,
            'created_at' => $backup->created_at?->toIso8601String(),
        ];
    }
}
