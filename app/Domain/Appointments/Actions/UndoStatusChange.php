<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Undo goes back through the saved history instead of a copy kept by the
// device: only the author's latest status change, while nothing else has
// happened to the appointment, and the reversal is logged like any change.
final class UndoStatusChange
{
    public const WINDOW_MINUTES = 10;

    public function __construct(private EnsureResponsibleIsAvailable $ensureResponsibleIsAvailable) {}

    public function handle(User $author, Appointment $appointment, ?AppointmentStatus $expected = null): Appointment
    {
        return DB::transaction(function () use ($author, $appointment, $expected): Appointment {
            Appointment::query()->whereKey($appointment->id)->lockForUpdate()->first();
            $appointment->refresh();

            $last = $appointment->activities()->first();

            if (! $this->canUndo($last, $author, $appointment)) {
                throw ValidationException::withMessages([
                    'status' => __('appointments.errors.undo_unavailable'),
                ])->status(409);
            }

            $previous = AppointmentStatus::from($last->old_values['status']);

            if ($expected !== null && $expected !== $previous) {
                throw ValidationException::withMessages([
                    'status' => __('appointments.errors.undo_unavailable'),
                ])->status(409);
            }

            $current = $appointment->status;

            if (in_array($previous, AppointmentStatus::blocking(), true) && ! in_array($current, AppointmentStatus::blocking(), true)) {
                $this->ensureResponsibleIsAvailable->handle($appointment->responsible, $appointment->starts_at, $appointment->ends_at, ignore: $appointment);
            }

            $appointment->update([
                'status' => $previous,
                'updated_by' => $author->id,
            ]);

            $appointment->recordActivity(
                ActivityType::StatusChanged,
                $author,
                ['status' => $current->value],
                ['status' => $previous->value],
            );

            return $appointment;
        });
    }

    private function canUndo(?AppointmentActivity $last, User $author, Appointment $appointment): bool
    {
        return $last !== null
            && in_array($last->event_type, [ActivityType::StatusChanged, ActivityType::Cancelled], true)
            && $last->user_id === $author->id
            && $last->created_at->greaterThan(now()->subMinutes(self::WINDOW_MINUTES))
            && ($last->new_values['status'] ?? null) === $appointment->status->value
            && AppointmentStatus::tryFrom($last->old_values['status'] ?? '') !== null;
    }
}
