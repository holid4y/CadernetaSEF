<?php

declare(strict_types=1);

namespace App\Domain\Google\Actions;

use App\Domain\Google\GoogleAccessRevoked;
use App\Domain\Google\GoogleCalendar;
use App\Jobs\PullGoogleEvents;
use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;

final class ChooseGoogleSource
{
    public function __construct(private GoogleCalendar $calendar) {}

    /**
     * Without a calendar, nothing is read from Google any more.
     *
     * @return ?string the name of the calendar chosen
     */
    public function handle(GoogleAccount $account, ?string $calendarId): ?string
    {
        $chosen = $calendarId === null ? null : collect($this->calendars($account))->firstWhere('id', $calendarId);

        if ($calendarId !== null && $chosen === null) {
            throw ValidationException::withMessages(['calendar' => __('google.errors.calendar')]);
        }

        // Events still waiting belong to the calendar that was left behind.
        if ($account->source_calendar_id !== $calendarId) {
            $account->events()->whereNull('appointment_id')->delete();
        }

        $account->update(['source_calendar_id' => $chosen['id'] ?? null, 'source_calendar_name' => $chosen['name'] ?? null]);

        if ($chosen !== null) {
            PullGoogleEvents::dispatch($account->id);
        }

        return $chosen['name'] ?? null;
    }

    /**
     * @return list<array{id: string, name: string, primary: bool}>
     */
    public function calendars(GoogleAccount $account): array
    {
        try {
            return $this->calendar->calendars($account);
        } catch (ConnectionException|RequestException|GoogleAccessRevoked) {
            throw ValidationException::withMessages(['calendar' => __('google.errors.calendars_unavailable')]);
        }
    }
}
