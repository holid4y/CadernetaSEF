<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))->setMinute(0)->setSecond(0);

        return [
            'client_id' => Client::factory(),
            'service_id' => Service::factory(),
            'responsible_user_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => AppointmentStatus::Scheduled,
            'created_by' => fn (array $attributes) => $attributes['responsible_user_id'],
        ];
    }

    /**
     * @param  string  $start  UTC, e.g. "2026-10-05 13:00"
     */
    public function between(string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => Carbon::parse($start, 'UTC'),
            'ends_at' => Carbon::parse($end, 'UTC'),
        ]);
    }

    // Already started, so its documents can be checked.
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->startOfHour(),
            'ends_at' => now()->startOfHour()->addHour(),
        ]);
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
