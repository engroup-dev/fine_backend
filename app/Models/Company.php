<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'default_currency',
        'overhead_absorption_enabled',
        'transfer_pricing_mode',
        'timezone',
        'fiscal_year_start_month',
        'annual_leave_days',
        'advance_max_salary_multiple',
        'advance_max_deduction_pct',
        'monthly_standard_hours',
        'overtime_multiplier',
    ];

    protected $casts = [
        'overhead_absorption_enabled' => 'boolean',
        'fiscal_year_start_month' => 'integer',
        'annual_leave_days' => 'integer',
        'advance_max_salary_multiple' => 'decimal:2',
        'advance_max_deduction_pct' => 'decimal:2',
        'monthly_standard_hours' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
    ];

    public function operatingUnits(): HasMany
    {
        return $this->hasMany(OperatingUnit::class);
    }
}
