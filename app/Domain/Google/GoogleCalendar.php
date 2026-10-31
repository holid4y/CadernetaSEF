<?php

declare(strict_types=1);

namespace App\Domain\Google;

use App\Models\GoogleAccount;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleCalendar
{
    public const NAME = 'Caderneta SEF';

    public const WEB_URL = 'https://calendar.google.com/calendar/r';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public function __construct(private GoogleAuthorization $authorization) {}

    // Links come from Google, but they end up in an href: only Google
    // Calendar addresses over HTTPS are kept.
    public static function link(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['port'])
            && in_array($host, ['calendar.google.com', 'www.google.com'], true)
            ? $url : null;
    }

    public function calendarFor(GoogleAccount $account): string
    {
        if ($account->calendar_id !== null) {
            return $account->calendar_id;
        }

        // Two copies sent at once must not create two calendars.
        return Cache::lock("google-calendar-{$account->id}", 30)->block(30, function () use ($account): string {
            $account->refresh();

            if ($account->calendar_id !== null) {
                return $account->calendar_id;
            }

            $id = $this->send($account, 'post', '/calendars', [
                'summary' => self::NAME,
                'description' => __('google.calendar_description'),
                'timeZone' => DisplayTimezone::name(),
            ])->throw()->json('id');

            if (! is_string($id)) {
                throw new RuntimeException('Google did not return the new calendar.');
            }

            $account->update(['calendar_id' => $id]);

            return $id;
        });
    }

    public function deleteCalendar(GoogleAccount $account): void
    {
        if ($account->calendar_id !== null) {
            $this->send($account, 'delete', '/calendars/'.rawurlencode($account->calendar_id));
        }
    }

    /**
     * @return list<array{id: string, name: string, primary: bool}>
     */
    public function calendars(GoogleAccount $account): array
    {
        $items = $this->send($account, 'get', '/users/me/calendarList', [
            'minAccessRole' => 'reader',
            'maxResults' => 250,
        ])->throw()->json('items', []);

        return collect($items)
            ->reject(fn (array $calendar): bool => $calendar['id'] === $account->calendar_id)
            ->map(fn (array $calendar): array => [
                'id' => $calendar['id'],
                'name' => mb_substr($calendar['summaryOverride'] ?? $calendar['summary'] ?? $calendar['id'], 0, 255),
                'primary' => ($calendar['primary'] ?? false) === true,
            ])
            ->sortBy([['primary', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * One page of the events of a calendar, with recurring events expanded
     * and deleted ones included so they can be removed here too.
     *
     * Null when the calendar was deleted or is no longer shared with the person.
     *
     * @return ?array{items?: list<array<string, mixed>>, nextPageToken?: string}
     */
    public function events(GoogleAccount $account, string $calendarId, CarbonInterface $from, CarbonInterface $to, ?string $page = null): ?array
    {
        $response = $this->send($account, 'get', '/calendars/'.rawurlencode($calendarId).'/events', array_filter([
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $to->toRfc3339String(),
            'maxResults' => 250,
            'pageToken' => $page,
            'fields' => 'items(id,status,summary,location,start,end,htmlLink,eventType),nextPageToken',
        ]));

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Creates or updates the copy with the given ID. The ID is fixed per
     * appointment, so running again never makes a second copy.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function putEvent(GoogleAccount $account, string $eventId, array $event): array
    {
        $response = $this->writeEvent($account, $eventId, $event);

        // The person deleted the Caderneta SEF calendar: a new one is made.
        if ($response->status() === 404) {
            $account->update(['calendar_id' => null]);
            $response = $this->writeEvent($account, $eventId, $event);
        }

        return $response->throw()->json();
    }

    public function deleteEvent(GoogleAccount $account, string $eventId): void
    {
        if ($account->calendar_id !== null) {
            $this->send($account, 'delete', '/calendars/'.rawurlencode($account->calendar_id).'/events/'.$eventId);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function writeEvent(GoogleAccount $account, string $eventId, array $event): Response
    {
        $path = '/calendars/'.rawurlencode($this->calendarFor($account)).'/events';
        $response = $this->send($account, 'put', "{$path}/{$eventId}", $event);

        if ($response->status() !== 404) {
            return $response;
        }

        $response = $this->send($account, 'post', $path, ['id' => $eventId, ...$event]);

        return $response->status() === 409 ? $this->send($account, 'put', "{$path}/{$eventId}", $event) : $response;
    }

    /**
     * Missing (404) and gone (410) answers are left to the caller; the other
     * failures are thrown, so a queued job tries again later.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(GoogleAccount $account, string $method, string $path, array $data = []): Response
    {
        $send = fn (): Response => Http::withToken($this->authorization->accessToken($account))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->{$method}(self::API.$path, $data);

        $response = $send();

        if ($response->status() === 401) {
            $account->update(['access_token' => null, 'access_token_expires_at' => null]);
            $response = $send();
        }

        if ($response->status() === 401) {
            $account->update(['failed_at' => now()]);

            throw new GoogleAccessRevoked;
        }

        if (! in_array($response->status(), [404, 409, 410], true)) {
            $response->throw();
        }

        return $response;
    }
}
