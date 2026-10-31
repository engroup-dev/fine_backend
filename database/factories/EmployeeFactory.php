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
        $payType = fake()->randomElement(PayType::cases());

        return [
            'entity_id' => Entity::factory()->individual(),
            'operating_unit_id' => OperatingUnit::factory(),
            'job_title' => fake()->jobTitle(),
            'labor_role' => fake()->randomElement(['operator', 'tailor', 'carpenter', 'upholsterer', 'assembler']),
            'pay_type' => $payType,
            'monthly_salary' => $payType === PayType::Monthly ? fake()->randomFloat(4, 800, 5000) : null,
            'hourly_rate' => $payType === PayType::Hourly ? fake()->randomFloat(4, 6, 25) : null,
            'daily_rate' => $payType === PayType::Daily ? fake()->randomFloat(4, 50, 200) : null,
            'hire_date' => fake()->dateTimeBetween('-5 years', '-30 days')->format('Y-m-d'),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => EmployeeStatus::Terminated]);
    }
}
