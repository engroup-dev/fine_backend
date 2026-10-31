<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAddress>
 */
class ClientAddressFactory extends Factory
{
    protected $model = ClientAddress::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'label' => fake()->randomElement(['Main store', 'Warehouse', 'Branch']),
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('09########'),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
