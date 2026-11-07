<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'service_category_id' => ServiceCategory::factory(),
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name),
            'requires_details' => false,
            'default_duration_minutes' => 60,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /**
     * @param  list<string>  $names
     */
    public function withDocuments(array $names): static
    {
        return $this->afterCreating(function (Service $service) use ($names): void {
            foreach ($names as $position => $name) {
                $service->documents()->create(['name' => $name, 'sort_order' => $position]);
            }
        });
    }
}
