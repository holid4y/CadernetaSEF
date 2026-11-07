<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Queries\UpcomingAppointments;
use App\Models\Appointment;
use App\Support\DisplayTimezone;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = UpcomingAppointments::today();
        $upcoming = UpcomingAppointments::afterToday();
        $week = $today->concat($upcoming);

        return view('dashboard', [
            'today' => $today,
            'upcoming' => $upcoming->take(8),
            'stats' => [
                'today' => $today->count(),
                'week' => $week->count(),
                'confirmed' => $week->where('status', AppointmentStatus::Confirmed)->count(),
                'completed' => Appointment::query()->where('status', AppointmentStatus::Completed)
                    ->where('starts_at', '>=', DisplayTimezone::toUtc(DisplayTimezone::toLocal(now())->startOfMonth()->format('Y-m-d H:i')))->count(),
            ],
        ]);
    }
}
