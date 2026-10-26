<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendAppointmentReminder
{
    /**
     * The automatic reminder goes through the queue. A reminder sent by hand
     * ($again) is delivered right away, so the person who asked for it only
     * hears "sent" after the mail server accepted the message.
     */
    public function handle(Appointment $appointment, bool $again = false): bool
    {
        if (! self::canBeSent($appointment)) {
            return false;
        }

        $previous = $appointment->reminder_sent_at;

        $claimed = Appointment::query()
            ->whereKey($appointment->id)
            ->unless($again, fn ($query) => $query->whereNull('reminder_sent_at'))
            ->update(['reminder_sent_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $appointment->refresh();

        if (! $again) {
            $appointment->client->notify(new AppointmentReminder($appointment));

            return true;
        }

        try {
            $appointment->client->notifyNow(new AppointmentReminder($appointment));
        } catch (Throwable $error) {
            Appointment::query()->whereKey($appointment->id)->update(['reminder_sent_at' => $previous]);
            $appointment->reminder_sent_at = $previous;

            // The mail server's answer may quote the address, so only its type is logged.
            Log::warning('Appointment reminder was not accepted by the mail server.', [
                'appointment_id' => $appointment->id,
                'error' => $error::class,
            ]);

            return false;
        }

        return true;
    }

    public static function canBeSent(Appointment $appointment): bool
    {
        return config('mail.reminders_enabled')
            && in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true)
            && $appointment->client->canReceiveReminders()
            && $appointment->starts_at->isFuture();
    }
}
