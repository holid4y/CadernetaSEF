<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\ReminderSchedule;
use App\Models\Appointment;
use App\Notifications\AppointmentConfirmation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the client, by e-mail and only with consent, that the appointment
 * was booked or moved. Queued after the transaction commits, so a booking
 * that rolls back sends nothing.
 */
final class SendAppointmentConfirmation
{
    public function handle(Appointment $appointment, bool $moved = false): bool
    {
        $appointment->loadMissing(['client', 'service', 'documents']);

        if (! SendAppointmentReminder::canBeSent($appointment)) {
            return false;
        }

        // Inside the reminder window the confirmation already carries everything.
        if (ReminderSchedule::isDue($appointment)) {
            Appointment::query()->whereKey($appointment->id)->update(['reminder_sent_at' => now()]);
            $appointment->setAttribute('reminder_sent_at', now())->syncOriginalAttribute('reminder_sent_at');
        }

        try {
            $appointment->client->notify(new AppointmentConfirmation($appointment, $moved));
        } catch (Throwable $error) {
            // The mail server's answer may quote the address, so only its type is logged.
            Log::warning('Appointment confirmation could not be queued.', [
                'appointment_id' => $appointment->id,
                'error' => $error::class,
            ]);

            return false;
        }

        return true;
    }
}
