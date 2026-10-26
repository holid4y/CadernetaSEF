<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\ActivityType;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Period and responsible are checked together; checking them one at a time
// reports conflicts that do not exist.
final class RescheduleAppointment
{
    public function __construct(private EnsureResponsibleIsAvailable $ensureResponsibleIsAvailable) {}

    public function handle(
        User $author,
        Appointment $appointment,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?User $responsible = null,
    ): Appointment {
        return DB::transaction(function () use ($author, $appointment, $startsAt, $endsAt, $responsible): Appointment {
            Appointment::query()->whereKey($appointment->id)->lockForUpdate()->first();
            $appointment->refresh();

            $responsible ??= $appointment->responsible;

            $movesPeriod = ! $appointment->starts_at->equalTo($startsAt) || ! $appointment->ends_at->equalTo($endsAt);
            $changesResponsible = $responsible->id !== $appointment->responsible_user_id;

            if (! $movesPeriod && ! $changesResponsible) {
                return $appointment;
            }

            if (! $appointment->status->canBeRescheduled()) {
                throw ValidationException::withMessages([
                    'starts_at' => __('appointments.errors.not_editable'),
                ]);
            }

            $this->ensureResponsibleIsAvailable->handle($responsible, $startsAt, $endsAt, ignore: $appointment);

            $before = [
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
                'responsible_user_id' => $appointment->responsible_user_id,
            ];

            $appointment->fill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'responsible_user_id' => $responsible->id,
                'updated_by' => $author->id,
            ]);

            // A reminder already sent carries the old time; the next run sends the new one.
            if ($movesPeriod) {
                $appointment->reminder_sent_at = null;
            }

            $appointment->save();

            if ($movesPeriod) {
                $appointment->recordActivity(
                    ActivityType::Rescheduled,
                    $author,
                    ['starts_at' => $before['starts_at'], 'ends_at' => $before['ends_at']],
                    ['starts_at' => $appointment->starts_at->toIso8601String(), 'ends_at' => $appointment->ends_at->toIso8601String()],
                );
            }

            if ($changesResponsible) {
                $appointment->recordActivity(
                    ActivityType::ResponsibleChanged,
                    $author,
                    ['responsible_user_id' => $before['responsible_user_id']],
                    ['responsible_user_id' => $responsible->id],
                );
            }

            return $appointment;
        });
    }
}
