<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Appointments\Actions\EnsureResponsibleIsAvailable;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Google\GoogleAccessRevoked;
use App\Domain\Google\GoogleCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// Brings the events of the calendar a person chose into the agenda. New ones
// wait for someone to complete them; a linked appointment follows the time
// set in Google, and a deletion there is only flagged.
final class PullGoogleEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const DAYS_AHEAD = 180;

    private const MAX_PAGES = 20;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("google-pull-{$this->accountId}"))->dontRelease()->expireAfter(600)];
    }

    public function handle(GoogleCalendar $calendar, RescheduleAppointment $reschedule): void
    {
        $account = GoogleAccount::query()->working()->with('user')->find($this->accountId);

        if ($account?->source_calendar_id === null) {
            return;
        }

        $from = now()->subDay();
        $to = now()->addDays(self::DAYS_AHEAD);
        $seen = [];
        $page = null;

        try {
            for ($pages = 0; $pages < self::MAX_PAGES; $pages++) {
                $result = $calendar->events($account, $account->source_calendar_id, $from, $to, $page);

                if ($result === null) {
                    $this->forgetSource($account);

                    return;
                }

                foreach ($result['items'] ?? [] as $item) {
                    if (! is_string($item['id'] ?? null) || strlen($item['id']) > 1024) {
                        continue;
                    }

                    $seen[] = $item['id'];
                    $this->apply($account, $item, $reschedule);
                }

                $page = $result['nextPageToken'] ?? null;

                if ($page === null) {
                    break;
                }
            }
        } catch (GoogleAccessRevoked) {
            return;
        }

        // Only a complete reading can tell which events left the calendar.
        if ($page === null) {
            GoogleEvent::query()
                ->where('google_account_id', $account->id)
                ->whereNull('appointment_id')
                ->overlapping($from, $to)
                ->whereNotIn('event_id', $seen)
                ->delete();
        }

        $account->update(['pulled_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function apply(GoogleAccount $account, array $item, RescheduleAppointment $reschedule): void
    {
        $event = GoogleEvent::query()->where('google_account_id', $account->id)->where('event_id', $item['id'])->first();
        $period = self::period($item);

        if ($period === null) {
            $this->leave($event, $item);

            return;
        }

        [$startsAt, $endsAt] = $period;
        $event ??= new GoogleEvent(['google_account_id' => $account->id, 'event_id' => $item['id']]);
        $moved = $event->exists && (! $event->starts_at->equalTo($startsAt) || ! $event->ends_at->equalTo($endsAt));

        $event->fill([
            'title' => mb_substr(trim((string) ($item['summary'] ?? '')), 0, 255) ?: __('google.untitled'),
            'location' => mb_substr(trim((string) ($item['location'] ?? '')), 0, 255) ?: null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'html_link' => GoogleCalendar::link($item['htmlLink'] ?? null),
            'removed_at' => null,
        ]);

        if ($moved && $event->appointment_id !== null) {
            $event->sync_error = $this->move($event, $account, $reschedule);
        }

        $event->save();
    }

    /**
     * A waiting event leaves the agenda; one already linked to an
     * appointment is only flagged, and only when it was deleted.
     *
     * @param  array<string, mixed>  $item
     */
    private function leave(?GoogleEvent $event, array $item): void
    {
        if ($event?->appointment_id === null) {
            $event?->delete();

            return;
        }

        if (($item['status'] ?? null) === 'cancelled') {
            $event->update(['removed_at' => $event->removed_at ?? now()]);
        }
    }

    private function move(GoogleEvent $event, GoogleAccount $account, RescheduleAppointment $reschedule): ?string
    {
        $appointment = Appointment::query()->find($event->appointment_id);

        if ($appointment === null) {
            return null;
        }

        try {
            $reschedule->handle($account->user, $appointment, $event->starts_at, $event->ends_at);
        } catch (ValidationException $error) {
            return mb_substr((string) collect($error->errors())->flatten()->first(), 0, 255);
        } catch (QueryException $error) {
            if ($error->getCode() !== '23P01') {
                throw $error;
            }

            return __('appointments.errors.slot_taken');
        }

        return null;
    }

    /**
     * Whole-day events, deleted ones and Google's own kinds (out of office,
     * birthdays) are not appointments.
     *
     * @param  array<string, mixed>  $item
     * @return ?array{0: Carbon, 1: Carbon}
     */
    private static function period(array $item): ?array
    {
        $start = $item['start']['dateTime'] ?? null;
        $end = $item['end']['dateTime'] ?? null;

        if (($item['status'] ?? null) === 'cancelled' || ($item['eventType'] ?? 'default') !== 'default' || ! is_string($start) || ! is_string($end)) {
            return null;
        }

        try {
            $startsAt = Carbon::parse($start)->utc();
            $endsAt = Carbon::parse($end)->utc();
        } catch (InvalidFormatException) {
            return null;
        }

        $minutes = $startsAt->diffInMinutes($endsAt, false);

        return $minutes > 0 && $minutes <= EnsureResponsibleIsAvailable::MAX_HOURS * 60 ? [$startsAt, $endsAt] : null;
    }

    // The calendar was deleted or is no longer shared with the person.
    private function forgetSource(GoogleAccount $account): void
    {
        $account->events()->whereNull('appointment_id')->delete();
        $account->update(['source_calendar_id' => null, 'source_calendar_name' => null]);
    }
}
