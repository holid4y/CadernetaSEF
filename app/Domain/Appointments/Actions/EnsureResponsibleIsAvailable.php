<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Models\Appointment;
use App\Models\User;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// Must run inside a transaction: the lock on the user row makes concurrent
// bookings for the same person wait instead of both passing the check.
final class EnsureResponsibleIsAvailable
{
    public const MAX_HOURS = 12;

    // One person can follow up to five clients at the same time.
    public const MAX_PARALLEL = 5;

    public function handle(User $responsible, CarbonInterface $startsAt, CarbonInterface $endsAt, ?Appointment $ignore = null): void
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => __('appointments.errors.invalid_period'),
            ]);
        }

        if ($startsAt->diffInMinutes($endsAt) > self::MAX_HOURS * 60) {
            throw ValidationException::withMessages([
                'ends_at' => __('appointments.errors.too_long', ['hours' => self::MAX_HOURS]),
            ]);
        }

        if (! $responsible->canWrite()) {
            throw ValidationException::withMessages([
                'responsible_user_id' => __('appointments.errors.responsible_unavailable', ['name' => $responsible->name]),
            ]);
        }

        User::query()->whereKey($responsible->id)->lockForUpdate()->first();

        $overlapping = Appointment::query()
            ->where('responsible_user_id', $responsible->id)
            ->blocking()
            ->overlapping($startsAt, $endsAt)
            ->when($ignore, fn (Builder $query) => $query->whereKeyNot($ignore->id))
            ->get(['id', 'starts_at', 'ends_at']);

        $full = self::fullStretch($overlapping, $startsAt, $endsAt);

        if ($full === null) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_at' => __('appointments.errors.full', [
                'name' => $responsible->name,
                'max' => self::MAX_PARALLEL,
                'start' => DisplayTimezone::toLocal($full[0])->format('H:i'),
                'end' => DisplayTimezone::toLocal($full[1])->format('H:i'),
            ]),
        ])->status(409);
    }

    /**
     * The first stretch of the new period in which the person already has
     * the maximum of appointments running at once, or null when it fits.
     *
     * @param  Collection<int, Appointment>  $appointments  all overlapping the period
     * @return ?array{0: Carbon, 1: Carbon}
     */
    private static function fullStretch(Collection $appointments, CarbonInterface $startsAt, CarbonInterface $endsAt): ?array
    {
        if ($appointments->count() < self::MAX_PARALLEL) {
            return null;
        }

        $from = $startsAt->getTimestamp();
        $until = $endsAt->getTimestamp();
        $times = [$from, $until];

        foreach ($appointments as $appointment) {
            $times[] = max($from, $appointment->starts_at->getTimestamp());
            $times[] = min($until, $appointment->ends_at->getTimestamp());
        }

        $times = array_values(array_unique($times));
        sort($times);
        $full = null;

        foreach (array_slice($times, 0, -1) as $index => $moment) {
            $running = $appointments->filter(fn (Appointment $appointment): bool => $appointment->starts_at->getTimestamp() <= $moment
                && $appointment->ends_at->getTimestamp() > $moment)->count();

            if ($running >= self::MAX_PARALLEL) {
                $full = [$full[0] ?? $moment, $times[$index + 1]];
            } elseif ($full !== null) {
                break;
            }
        }

        return $full === null ? null : [Carbon::createFromTimestamp($full[0]), Carbon::createFromTimestamp($full[1])];
    }
}
