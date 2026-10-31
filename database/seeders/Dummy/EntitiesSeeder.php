<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\ClientStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EntityRoleType;
use App\Enums\PayType;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\EntityContact;
use App\Models\EntityRole;
use App\Models\OperatingUnit;
use App\Support\SalesAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dummy clients and employees across the operating units.
 * Caps intentionally moderate so reports and lists are populated without
 * flooding dashboards.
 */
class EntitiesSeeder extends Seeder
{
    public function run(): void
    {
        $units = OperatingUnit::orderBy('name')->get();
        if ($units->isEmpty()) {
            return;
        }

        $showroom = $units->firstWhere('name', 'صالة العرض') ?? $units->first();

        $this->seedClients($showroom);
        $this->seedEmployees($units);
    }

    private function seedClients(OperatingUnit $unit): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $entity = Entity::factory()->organization()->create();

            EntityContact::create([
                'id' => (string) Str::uuid(),
                'entity_id' => $entity->id,
                'email' => 'contact+'.$i.'@'.Str::slug($entity->name).'.example',
                'phone' => '+218-91-'.str_pad((string) fake()->numberBetween(100, 999), 3, '0', STR_PAD_LEFT).'-'.fake()->numberBetween(1000, 9999),
                'city' => fake()->randomElement(['Tripoli', 'Benghazi', 'Misrata']),
                'address' => fake()->streetAddress(),
                'is_primary' => true,
            ]);

            EntityRole::create([
                'id' => (string) Str::uuid(),
                'entity_id' => $entity->id,
                'role_type' => EntityRoleType::Client,
                'operating_unit_id' => $unit->id,
            ]);

            $client = Client::create([
                'id' => (string) Str::uuid(),
                'entity_id' => $entity->id,
                'operating_unit_id' => $unit->id,
                'credit_limit' => fake()->randomFloat(4, 25_000, 500_000),
                'payment_terms_days' => fake()->randomElement([30, 45, 60]),
                'status' => fake()->randomElement(ClientStatus::cases()),
            ]);

            // Credit sales post to the client's own sub-account under 122.
            SalesAccounts::receivableFor($client);
        }
    }

    private function seedEmployees($units): void
    {
        foreach ($units as $unit) {
            for ($i = 1; $i <= 3; $i++) {
                $entity = Entity::factory()->individual()->create();

                EntityRole::create([
                    'id' => (string) Str::uuid(),
                    'entity_id' => $entity->id,
                    'role_type' => EntityRoleType::Employee,
                    'operating_unit_id' => $unit->id,
                ]);

                Employee::create([
                    'id' => (string) Str::uuid(),
                    'entity_id' => $entity->id,
                    'operating_unit_id' => $unit->id,
                    'job_title' => fake()->jobTitle(),
                    'labor_role' => fake()->randomElement(['operator', 'tailor', 'carpenter', 'upholsterer', 'assembler']),
                    'pay_type' => fake()->randomElement(PayType::cases()),
                    'monthly_salary' => fake()->randomFloat(4, 800, 5000),
                    'hourly_rate' => fake()->randomFloat(4, 6, 25),
                    'daily_rate' => fake()->randomFloat(4, 50, 200),
                    'hire_date' => fake()->dateTimeBetween('-5 years', '-30 days')->format('Y-m-d'),
                    'status' => EmployeeStatus::Active,
                ]);
            }
        }
    }
}
