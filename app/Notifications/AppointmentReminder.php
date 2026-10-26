<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Appointments\Actions\SendAppointmentReminder;
use App\Domain\Appointments\ReminderMessage;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AppointmentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Appointment $appointment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    // The queue may run minutes later: by then the appointment can be
    // cancelled or the client may have withdrawn consent.
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return SendAppointmentReminder::canBeSent($this->appointment->fresh() ?? $this->appointment);
    }

    // After the last try the reminder no longer counts as sent, so the next
    // run of the scheduler tries again while the appointment is ahead.
    public function failed(Throwable $error): void
    {
        Appointment::query()->whereKey($this->appointment->id)->update(['reminder_sent_at' => null]);

        // The mail server's answer may quote the address, so only its type is logged.
        Log::warning('Appointment reminder was not accepted by the mail server.', [
            'appointment_id' => $this->appointment->id,
            'error' => $error::class,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = array_map(self::plain(...), ReminderMessage::lines($this->appointment));
        $message = (new MailMessage)
            ->subject(ReminderMessage::subject($this->appointment))
            ->greeting(array_shift($lines));

        foreach ($lines as $line) {
            $message->line($line);
        }

        return $message
            ->action(__('reminders.respond_action'), ReminderMessage::responseUrl($this->appointment))
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
