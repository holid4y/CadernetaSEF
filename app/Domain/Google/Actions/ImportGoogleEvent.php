<?php

declare(strict_types=1);

namespace App\Domain\Google\Actions;

use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Models\Appointment;
use App\Models\GoogleEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportGoogleEvent
{
    public function __construct(private ScheduleAppointment $schedule) {}

    /**
     * @param  array<string, mixed>  $attributes  as ScheduleAppointment takes them
     */
    public function handle(User $author, int $googleEventId, array $attributes): Appointment
    {
        return DB::transaction(function () use ($author, $googleEventId, $attributes): Appointment {
            // Two people completing the same event: the second one waits and is refused.
            $event = GoogleEvent::query()->lockForUpdate()->findOrFail($googleEventId);

            if (! $event->isPending()) {
                throw ValidationException::withMessages([
                    'google_event' => __('google.errors.already_imported'),
                ])->status(409);
            }

            $appointment = $this->schedule->handle($author, $attributes);
            $event->update(['appointment_id' => $appointment->id, 'sync_error' => null]);

            return $appointment;
        });
    }
}
