<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Enums\PayType;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory()->individual(),
            'operating_unit_id' => OperatingUnit::factory(),
            'job_title' => fake()->jobTitle(),
            'labor_role' => fake()->randomElement(['operator', 'tailor', 'carpenter', 'upholsterer', 'assembler']),
            'pay_type' => fake()->randomElement(PayType::cases()),
            // The rates are resolved from the final pay_type, so an overridden
            // pay_type gets its own rate and no stray rate of another type.
            'monthly_salary' => fn (array $attributes) => self::payTypeOf($attributes['pay_type']) === PayType::Monthly
                ? fake()->randomFloat(4, 800, 5000)
                : null,
            'hourly_rate' => fn (array $attributes) => self::payTypeOf($attributes['pay_type']) === PayType::Hourly
                ? fake()->randomFloat(4, 6, 25)
                : null,
            'daily_rate' => fn (array $attributes) => self::payTypeOf($attributes['pay_type']) === PayType::Daily
                ? fake()->randomFloat(4, 50, 200)
                : null,
            'hire_date' => fake()->dateTimeBetween('-5 years', '-30 days')->format('Y-m-d'),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => ['pay_type' => PayType::Monthly]);
    }

    public function hourly(): static
    {
        return $this->state(fn () => ['pay_type' => PayType::Hourly]);
    }

    public function daily(): static
    {
        return $this->state(fn () => ['pay_type' => PayType::Daily]);
    }

    private static function payTypeOf(PayType|string|null $payType): ?PayType
    {
        return is_string($payType) ? PayType::tryFrom($payType) : $payType;
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => EmployeeStatus::Terminated]);
    }
}
