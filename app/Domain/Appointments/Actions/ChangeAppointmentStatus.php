<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeAppointmentStatus
{
    /**
     * A null author means the client answered through the reminder link.
     */
    public function handle(?User $author, Appointment $appointment, AppointmentStatus $next): Appointment
    {
        return DB::transaction(function () use ($author, $appointment, $next): Appointment {
            // Two people changing the same appointment wait for each other,
            // so the transition is checked against the status that was saved.
            Appointment::query()->whereKey($appointment->id)->lockForUpdate()->first();
            $appointment->refresh();

            $current = $appointment->status;

            if (! $current->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => __('appointments.errors.transition_not_allowed', [
                        'from' => $current->label(),
                        'to' => $next->label(),
                    ]),
                ]);
            }

            $appointment->update([
                'status' => $next,
                'updated_by' => $author?->id,
            ]);

            $appointment->recordActivity(
                $next === AppointmentStatus::Cancelled ? ActivityType::Cancelled : ActivityType::StatusChanged,
                $author,
                ['status' => $current->value],
                ['status' => $next->value],
            );

            return $appointment;
        });
    }
}
