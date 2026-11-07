<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Appointments\Actions\SendAppointmentReminder;
use App\Domain\Appointments\ReminderMessage;
use App\Domain\Appointments\ReminderSchedule;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AppointmentConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    private Carbon $startsAt;

    public function __construct(public Appointment $appointment, public bool $moved = false)
    {
        $this->startsAt = $appointment->starts_at->copy();
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    // By the time the queue runs, the appointment may have been cancelled or
    // moved again; a newer confirmation then carries the right time.
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = $this->appointment->fresh();

        return $current !== null
            && $current->starts_at->equalTo($this->startsAt)
            && SendAppointmentReminder::canBeSent($current);
    }

    public function failed(Throwable $error): void
    {
        // The mail server's answer may quote the address, so only its type is logged.
        Log::warning('Appointment confirmation was not accepted by the mail server.', [
            'appointment_id' => $this->appointment->id,
            'error' => $error::class,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = $this->appointment->fresh(['client', 'service', 'documents']) ?? $this->appointment;
        $lines = array_map(self::plain(...), ReminderMessage::lines($appointment));
        $date = ReminderMessage::localStart($appointment)->format('d/m');
        $message = (new MailMessage)
            ->subject(__($this->moved ? 'reminders.confirmation.subject_moved' : 'reminders.confirmation.subject', ['date' => $date]))
            ->greeting(array_shift($lines))
            ->line(__($this->moved ? 'reminders.confirmation.moved' : 'reminders.confirmation.booked'));

        foreach ($lines as $line) {
            $message->line($line);
        }

        if (! ReminderSchedule::isDue($appointment)) {
            $message->line(trans_choice('reminders.confirmation.reminder_ahead', ReminderSchedule::daysBefore(), ['count' => ReminderSchedule::daysBefore()]));
        }

        return $message
            ->action(__('reminders.respond_action'), ReminderMessage::responseUrl($appointment))
            ->line(__('reminders.respond_note'))
            ->salutation(__('reminders.salutation'));
    }

    // Mail lines are rendered as Markdown: a name or place typed as
    // "[text](url)" would otherwise become a link in the project's e-mail.
    private static function plain(string $line): string
    {
        return addcslashes($line, '\\`*_[]()#<>|~!');
    }
}
