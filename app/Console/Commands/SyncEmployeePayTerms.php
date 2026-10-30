<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Scopes\OperatingUnitScope;
use App\Services\EmployeeCompensationService;
use App\Support\CompanyClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HR-11: the employee row keeps a copy of the pay terms in force today. A
 * future-dated pay change only becomes today's terms once its date arrives,
 * so the scheduler (routes/console.php) re-syncs the rows daily. Rows whose
 * terms already match are not saved. Idempotent; safe to run by hand.
 */
final class SyncEmployeePayTerms extends Command
{
    protected $signature = 'hr:sync-pay-terms';

    protected $description = 'Copy the pay terms in force today from the pay history onto each employee';

    public function handle(EmployeeCompensationService $compensations): int
    {
        $today = CompanyClock::today();
        $updated = 0;
        $failed = 0;

        Employee::withoutGlobalScope(OperatingUnitScope::class)
            ->whereHas('compensations')
            ->select('id')
            ->chunkById(200, function ($employees) use ($compensations, $today, &$updated, &$failed): void {
                foreach ($employees as $row) {
                    try {
                        $changed = DB::transaction(function () use ($compensations, $row, $today): bool {
                            $employee = Employee::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);

                            return $compensations->syncCurrentTerms($employee, $today);
                        });

                        if ($changed) {
                            $updated++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("{$row->id}: {$e->getMessage()}");
                        report($e);
                    }
                }
            });

        $this->info("Pay terms {$today}: {$updated} employee(s) updated, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
