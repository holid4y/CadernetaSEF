<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ClientType::Individual,
            'name' => fake()->name(),
            'phone' => fake()->numerify('(51) 9####-####'),
            'email' => fake()->unique()->safeEmail(),
            'accepts_reminders' => false,
            'active' => true,
        ];
    }

    public function organization(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ClientType::Organization,
            'name' => fake()->company(),
            'trade_name' => fake()->companySuffix(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
