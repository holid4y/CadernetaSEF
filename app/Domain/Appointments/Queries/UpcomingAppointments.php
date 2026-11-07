<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Queries;

use App\Models\Appointment;
use App\Support\DisplayTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class UpcomingAppointments
{
    /**
     * The local day, not the UTC one: at 22:00 in São Paulo it is already tomorrow in UTC.
     *
     * @return Collection<int, Appointment>
     */
    public static function today(): Collection
    {
        $today = DisplayTimezone::toLocal(now());

        return self::base()
            ->whereBetween('starts_at', [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()])
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    public static function afterToday(int $days = 7): Collection
    {
        $tomorrow = DisplayTimezone::toLocal(now())->addDay()->startOfDay();

        return self::base()
            ->whereBetween('starts_at', [$tomorrow->copy()->utc(), $tomorrow->copy()->addDays($days)->utc()])
            ->get();
    }

    /**
     * @return Builder<Appointment>
     */
    private static function base(): Builder
    {
        return Appointment::query()
            ->with(['client', 'service', 'responsible', 'documents'])
            ->blocking()
            ->orderBy('starts_at');
    }
}
