<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClientGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientGroup>
 */
class ClientGroupFactory extends Factory
{
    protected $model = ClientGroup::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('GRP-###')),
            'name' => fake()->randomElement(['Retail', 'Contractors', 'Hotels']),
            'default_credit_limit' => 0,
            'default_payment_terms_days' => 30,
            'default_price_tier' => 'retail',
        ];
    }

    public function wholesale(): static
    {
        return $this->state(fn () => [
            'name' => 'Wholesale',
            'default_credit_limit' => 5000,
            'default_payment_terms_days' => 60,
            'default_price_tier' => 'wholesale',
        ]);
    }
}
