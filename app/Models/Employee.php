<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\PayType;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Employee extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    /**
     * Bank details are shown only to the roles that set pay, through
     * EmployeeResource. Hidden here so raw JSON (leave requests, attendance,
     * payslips) never carries them and the audit log redacts them.
     */
    protected $hidden = ['bank_name', 'bank_account'];

    protected $fillable = [
        'entity_id',
        'employee_number',
        'operating_unit_id',
        'job_title',
        'labor_role',
        'pay_type',
        'monthly_salary',
        'hourly_rate',
        'hire_date',
        'contract_start',
        'contract_end',
        'probation_end',
        'termination_date',
        'bank_name',
        'bank_account',
        'status',
        'record_version',
    ];

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'status' => EmployeeStatus::class,
            'monthly_salary' => 'decimal:4',
            'hourly_rate' => 'decimal:4',
            'hire_date' => 'date',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'probation_end' => 'date',
            'termination_date' => 'date',
            'record_version' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * W5-02 (HR-12): salary advances and loans.
     */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    /**
     * HR-11: pay terms history, oldest first.
     */
    public function compensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class)->orderBy('effective_from');
    }
}
