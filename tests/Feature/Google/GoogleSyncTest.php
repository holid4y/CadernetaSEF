<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleCalendar;
use App\Jobs\SyncAppointmentToGoogle;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\GoogleEventCopy;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleSyncTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://www.googleapis.com/calendar/v3';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
        $this->travelTo('2026-10-05 12:00');
    }

    public function test_a_new_appointment_shows_up_in_the_responsibles_calendar(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, 'ana-sef');
        $this->fakeCalendar();

        $appointment = $this->schedule($ana, ['document' => '529.982.247-25', 'phone' => '51999990000', 'notes' => 'Prefere manhã']);

        $sent = $this->sent('POST', '/calendars/ana-sef/events');
        $this->assertSame(SyncAppointmentToGoogle::eventId($appointment), $sent['id']);
        $this->assertSame('IRPF · Joana Lima', $sent['summary']);
        $this->assertSame(['dateTime' => '2026-10-06T13:00:00+00:00', 'timeZone' => 'America/Sao_Paulo'], $sent['start']);
        $this->assertStringContainsString(route('appointments.show', $appointment), $sent['description']);
        $this->assertStringNotContainsString('529', json_encode($sent));
        $this->assertStringNotContainsString('Prefere', json_encode($sent));
        $this->assertSame('https://www.google.com/calendar/event?eid=abc', GoogleEventCopy::query()->where('google_account_id', $account->id)->sole()->html_link);
    }

    public function test_appointments_of_people_without_google_send_nothing(): void
    {
        Http::fake();
        Queue::fake();

        $this->schedule(User::factory()->create());

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_the_caderneta_calendar_is_created_once_when_missing(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, null);
        $this->fakeCalendar();

        $this->schedule($ana);
        $this->schedule($ana, start: '2026-10-07 13:00');

        $this->assertSame('created-calendar', $account->fresh()->calendar_id);
        $created = Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::API.'/calendars');
        $this->assertCount(1, $created);
        $this->assertSame('Caderneta SEF', $created[0][0]['summary']);
    }

    public function test_cancelling_removes_the_copy(): void
    {
        $ana = User::factory()->create();
        $this->connected($ana, 'ana-sef');
        $this->fakeCalendar();
        $appointment = $this->schedule($ana);

        app(ChangeAppointmentStatus::class)->handle($ana, $appointment, AppointmentStatus::Cancelled);

        $this->sent('DELETE', '/calendars/ana-sef/events/'.SyncAppointmentToGoogle::eventId($appointment));
        $this->assertDatabaseEmpty('google_event_copies');
    }

    public function test_a_new_responsible_takes_the_copy_with_them(): void
    {
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $this->connected($ana, 'ana-sef');
        $brunoAccount = $this->connected($bruno, 'bruno-sef', 'bruno.google@gmail.com');
        $this->fakeCalendar();
        $appointment = $this->schedule($ana);

        app(RescheduleAppointment::class)->handle($ana, $appointment, $appointment->starts_at, $appointment->ends_at, $bruno);

        $this->sent('DELETE', '/calendars/ana-sef/events/'.SyncAppointmentToGoogle::eventId($appointment));
        $this->sent('PUT', '/calendars/bruno-sef/events/'.SyncAppointmentToGoogle::eventId($appointment));
        $this->assertSame($brunoAccount->id, GoogleEventCopy::query()->sole()->google_account_id);
    }

    public function test_access_removed_in_google_stops_the_sync_and_asks_to_reconnect(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, 'ana-sef', expired: true);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->schedule($ana);

        $this->assertNotNull($account->fresh()->failed_at);
        Http::assertSentCount(1);
    }

    public function test_an_event_brought_from_google_is_not_copied_back_to_the_same_account(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, 'ana-sef');
        $this->fakeCalendar();
        $appointment = $this->schedule($ana);
        GoogleEvent::query()->create([
            'google_account_id' => $account->id, 'event_id' => 'original', 'title' => 'Joana', 'appointment_id' => $appointment->id,
            'starts_at' => $appointment->starts_at, 'ends_at' => $appointment->ends_at,
        ]);

        (new SyncAppointmentToGoogle($appointment->id))->handle(app(GoogleCalendar::class));

        $this->assertDatabaseEmpty('google_event_copies');
    }

    public function test_new_events_of_the_chosen_calendar_wait_to_be_completed(): void
    {
        $account = $this->connected(User::factory()->create(), 'ana-sef', source: 'sef@group.calendar.google.com');
        $this->fakeSource([
            $this->googleEvent('timed', '2026-10-06T10:00:00-03:00', '2026-10-06T11:00:00-03:00', ['summary' => 'Joana - IRPF', 'location' => 'Sala 2']),
            ['id' => 'all-day', 'status' => 'confirmed', 'summary' => 'Feriado', 'start' => ['date' => '2026-10-07'], 'end' => ['date' => '2026-10-08']],
            $this->googleEvent('away', '2026-10-06T13:00:00-03:00', '2026-10-06T18:00:00-03:00', ['eventType' => 'outOfOffice']),
            $this->googleEvent('long', '2026-10-06T08:00:00-03:00', '2026-10-06T22:00:00-03:00'),
            ['id' => 'gone', 'status' => 'cancelled'],
        ]);

        $this->artisan('google:pull')->assertSuccessful();

        $event = GoogleEvent::query()->sole();
        $this->assertSame(['timed', 'Joana - IRPF', 'Sala 2'], [$event->event_id, $event->title, $event->location]);
        $this->assertTrue($event->starts_at->equalTo(Carbon::parse('2026-10-06 13:00', 'UTC')));
        $this->assertTrue($event->isPending());
        $this->assertNotNull($account->fresh()->pulled_at);
    }

    public function test_events_that_left_google_leave_the_agenda_while_still_waiting(): void
    {
        $account = $this->connected(User::factory()->create(), 'ana-sef', source: 'sef');
        GoogleEvent::query()->create(['google_account_id' => $account->id, 'event_id' => 'old', 'title' => 'Antigo', 'starts_at' => '2026-10-06 13:00', 'ends_at' => '2026-10-06 14:00']);
        $this->fakeSource([]);

        $this->artisan('google:pull');

        $this->assertDatabaseEmpty('google_events');
    }

    public function test_a_linked_appointment_follows_the_time_set_in_google(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, null, source: 'sef');
        $this->fakeSource([$this->googleEvent('original', '2026-10-06T15:00:00-03:00', '2026-10-06T16:00:00-03:00')]);
        $appointment = $this->linkedAppointment($account, $ana);

        $this->artisan('google:pull');

        $this->assertTrue($appointment->fresh()->starts_at->equalTo(Carbon::parse('2026-10-06 18:00', 'UTC')));
        $this->assertNull(GoogleEvent::query()->sole()->sync_error);
        $this->assertSame('rescheduled', $appointment->activities()->first()->event_type->value);
    }

    public function test_a_move_in_google_that_clashes_is_flagged_instead_of_applied(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, null, source: 'sef');
        $this->fakeSource([$this->googleEvent('original', '2026-10-06T15:00:00-03:00', '2026-10-06T16:00:00-03:00')]);
        $appointment = $this->linkedAppointment($account, $ana);
        Appointment::factory()->for($ana, 'responsible')->between('2026-10-06 18:00', '2026-10-06 19:00')->create();

        $this->artisan('google:pull');

        $this->assertTrue($appointment->fresh()->starts_at->equalTo(Carbon::parse('2026-10-06 13:00', 'UTC')));
        $this->assertNotNull(GoogleEvent::query()->sole()->sync_error);
    }

    public function test_a_linked_event_deleted_in_google_is_only_flagged(): void
    {
        $ana = User::factory()->create();
        $account = $this->connected($ana, null, source: 'sef');
        $this->fakeSource([['id' => 'original', 'status' => 'cancelled']]);
        $appointment = $this->linkedAppointment($account, $ana);

        $this->artisan('google:pull');

        $this->assertNotNull(GoogleEvent::query()->sole()->removed_at);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_a_calendar_that_was_deleted_stops_the_import(): void
    {
        $account = $this->connected(User::factory()->create(), 'ana-sef', source: 'sef');
        Http::fake([self::API.'/calendars/sef/events*' => Http::response(['error' => ['code' => 404]], 404)]);

        $this->artisan('google:pull');

        $this->assertNull($account->fresh()->source_calendar_id);
    }

    public function test_links_outside_google_calendar_are_dropped(): void
    {
        $this->connected(User::factory()->create(), 'ana-sef', source: 'sef');
        $this->fakeSource([$this->googleEvent('timed', '2026-10-06T10:00:00-03:00', '2026-10-06T11:00:00-03:00', [
            'summary' => '<img src=x onerror=alert(1)>',
            'htmlLink' => 'javascript:alert(1)',
        ])]);

        $this->artisan('google:pull');

        $event = GoogleEvent::query()->sole();
        $this->assertNull($event->html_link);
        $this->assertSame('<img src=x onerror=alert(1)>', $event->title);
    }

    public function test_the_import_runs_every_five_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('google:pull');
    }

    private function connected(User $user, ?string $calendar, string $email = 'ana.google@gmail.com', ?string $source = null, bool $expired = false): GoogleAccount
    {
        return GoogleAccount::query()->create([
            'user_id' => $user->id,
            'email' => $email,
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => $expired ? now()->subMinute() : now()->addHour(),
            'scopes' => implode(' ', GoogleAuthorization::SCOPES),
            'calendar_id' => $calendar,
            'source_calendar_id' => $source,
        ]);
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function schedule(User $responsible, array $client = [], string $start = '2026-10-06 13:00'): Appointment
    {
        return app(ScheduleAppointment::class)->handle($responsible, [
            'client_id' => Client::factory()->create(['name' => 'Joana Lima', ...$client])->id,
            'service_id' => Service::factory()->create(['name' => 'IRPF'])->id,
            'responsible_user_id' => $responsible->id,
            'starts_at' => Carbon::parse($start, 'UTC'),
            'ends_at' => Carbon::parse($start, 'UTC')->addHour(),
        ]);
    }

    private function linkedAppointment(GoogleAccount $account, User $responsible): Appointment
    {
        $appointment = Appointment::factory()->for($responsible, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();
        GoogleEvent::query()->create([
            'google_account_id' => $account->id, 'event_id' => 'original', 'title' => 'Joana', 'appointment_id' => $appointment->id,
            'starts_at' => $appointment->starts_at, 'ends_at' => $appointment->ends_at,
        ]);

        return $appointment;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function googleEvent(string $id, string $start, string $end, array $extra = []): array
    {
        return ['id' => $id, 'status' => 'confirmed', 'summary' => 'Evento', 'start' => ['dateTime' => $start], 'end' => ['dateTime' => $end],
            'htmlLink' => "https://www.google.com/calendar/event?eid={$id}", 'eventType' => 'default', ...$extra];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeSource(array $items): void
    {
        Http::fake([self::API.'/calendars/sef*' => Http::response(['items' => $items]), '*' => Http::response(['id' => 'created-calendar', 'htmlLink' => 'https://www.google.com/calendar/event?eid=abc'])]);
    }

    private function fakeCalendar(): void
    {
        Http::fake(fn (Request $request) => match ($request->method()) {
            'PUT' => Http::response(['error' => ['code' => 404]], 404),
            'DELETE' => Http::response(status: 204),
            default => Http::response(['id' => 'created-calendar', 'htmlLink' => 'https://www.google.com/calendar/event?eid=abc']),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function sent(string $method, string $path): array
    {
        $requests = Http::recorded(fn (Request $request): bool => $request->method() === $method && $request->url() === self::API.$path);
        $this->assertNotEmpty($requests, "{$method} {$path} was not sent.");

        return $requests->first()[0]->data();
    }
}
