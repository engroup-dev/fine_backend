<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * HR-11: every existing employee gets an opening compensation row carrying
     * today's pay terms from the hire date, so payroll can resolve pay by date.
     * Idempotent: employees that already have history are left alone.
     */
    public function up(): void
    {
        $now = now();

        DB::table('employees')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('employee_compensations')
                    ->whereColumn('employee_compensations.employee_id', 'employees.id');
            })
            ->chunkById(200, function ($employees) use ($now): void {
                $rows = [];

                foreach ($employees as $employee) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'employee_id' => $employee->id,
                        'pay_type' => $employee->pay_type,
                        'monthly_salary' => $employee->monthly_salary,
                        'hourly_rate' => $employee->hourly_rate,
                        'effective_from' => $employee->hire_date,
                        'reason' => 'opening', // EmployeeCompensation::OPENING_REASON
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('employee_compensations')->insert($rows);
            }, 'employees.id', 'id');
    }

    public function down(): void
    {
        DB::table('employee_compensations')->where('reason', 'opening')->whereNull('created_by')->delete();
    }
};
