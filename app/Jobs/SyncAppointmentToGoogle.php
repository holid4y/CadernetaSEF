<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Google\GoogleAccessRevoked;
use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\GoogleEventCopy;
use App\Support\DisplayTimezone;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

// Keeps the copy of an appointment in the Caderneta SEF calendar of its
// responsible. It reads the appointment as it is when it runs, so a late or
// repeated run still ends in the right state.
final class SyncAppointmentToGoogle implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $appointmentId) {}

    public static function dispatchFor(Appointment $appointment): void
    {
        if (! GoogleAuthorization::configured()) {
            return;
        }

        $involved = GoogleAccount::query()->where('user_id', $appointment->responsible_user_id)->exists()
            || GoogleEventCopy::query()->where('appointment_id', $appointment->id)->exists();

        if ($involved) {
            self::dispatch($appointment->id)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->appointmentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-appointment-{$this->appointmentId}"))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(GoogleCalendar $calendar): void
    {
        $appointment = Appointment::withTrashed()->with(['client', 'service'])->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $target = $this->target($appointment);

        GoogleEventCopy::query()
            ->with('account')
            ->where('appointment_id', $appointment->id)
            ->when($target, fn ($query) => $query->where('google_account_id', '!=', $target->id))
            ->get()
            ->each(function (GoogleEventCopy $copy) use ($calendar, $appointment): void {
                try {
                    $calendar->deleteEvent($copy->account, self::eventId($appointment));
                } catch (GoogleAccessRevoked) {
                }

                $copy->delete();
            });

        if ($target === null) {
            return;
        }

        try {
            $event = $calendar->putEvent($target, self::eventId($appointment), self::event($appointment));
        } catch (GoogleAccessRevoked) {
            return;
        }

        GoogleEventCopy::query()->updateOrCreate(
            ['appointment_id' => $appointment->id, 'google_account_id' => $target->id],
            ['html_link' => GoogleCalendar::link($event['htmlLink'] ?? null)],
        );
    }

    // Google accepts IDs made of 0-9 and a-v; the hexadecimal UUID fits.
    public static function eventId(Appointment $appointment): string
    {
        return str_replace('-', '', $appointment->integration_id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(Appointment $appointment): array
    {
        $timezone = DisplayTimezone::name();
        $url = route('appointments.show', $appointment);

        return [
            'summary' => "{$appointment->service->name} · {$appointment->client->name}",
            'location' => collect([$appointment->location_type?->label(), $appointment->location])->filter()->implode(' · '),
            'description' => __('google.event_description', ['url' => $url]),
            'start' => ['dateTime' => $appointment->starts_at->toRfc3339String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $appointment->ends_at->toRfc3339String(), 'timeZone' => $timezone],
            'status' => 'confirmed',
            'source' => ['title' => GoogleCalendar::NAME, 'url' => $url],
            'extendedProperties' => ['private' => ['caderneta' => $appointment->integration_id]],
        ];
    }

    private function target(Appointment $appointment): ?GoogleAccount
    {
        if ($appointment->trashed() || $appointment->status === AppointmentStatus::Cancelled) {
            return null;
        }

        $account = GoogleAccount::query()->working()->where('user_id', $appointment->responsible_user_id)->first();

        // Whoever brought the event from their own Google calendar already has it there.
        $imported = $account !== null && GoogleEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('google_account_id', $account->id)
            ->exists();

        return $imported ? null : $account;
    }
}
