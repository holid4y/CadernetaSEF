<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleAuthorization;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\GoogleEventCopy;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleMobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
        Queue::fake();
        $this->travelTo('2026-10-05 12:00');
    }

    public function test_the_app_gets_the_connection_and_the_events_waiting_in_google(): void
    {
        $ana = User::factory()->create();
        $account = $this->connect($ana, ['source_calendar_id' => 'sef', 'source_calendar_name' => 'Atendimentos SEF']);
        $event = $this->waiting($account, 'Joana - IRPF');
        GoogleEvent::query()->create([...$event->only('google_account_id', 'starts_at', 'ends_at'), 'event_id' => 'dismissed', 'title' => 'Dentista', 'dismissed_at' => now()]);
        Sanctum::actingAs($ana, ['mobile']);

        $state = $this->getJson(route('api.mobile.bootstrap'))->assertOk()->json('state');

        $this->assertSame(['available' => true, 'connected' => true, 'email' => 'ana.google@gmail.com', 'calendarName' => 'Atendimentos SEF'],
            array_intersect_key($state['google'], ['available' => 0, 'connected' => 0, 'email' => 0, 'calendarName' => 0]));
        $this->assertSame([['id' => $event->id, 'title' => 'Joana - IRPF', 'ownerId' => $ana->id]],
            array_map(fn (array $item): array => array_intersect_key($item, ['id' => 0, 'title' => 0, 'ownerId' => 0]), $state['googleEvents']));
    }

    public function test_completing_an_event_in_the_app_links_it_once(): void
    {
        $ana = User::factory()->create();
        $event = $this->waiting($this->connect($ana), 'Joana - IRPF');
        $input = [
            'clientId' => Client::factory()->create()->id,
            'serviceSlug' => Service::factory()->create()->slug,
            'responsibleId' => $ana->id,
            'start' => $event->starts_at->getTimestampMs(),
            'end' => $event->ends_at->getTimestampMs(),
            'googleEventId' => $event->id,
        ];
        Sanctum::actingAs($ana, ['mobile']);

        $this->postJson(route('api.mobile.commands'), ['name' => 'scheduleAppointment', 'args' => [$input]])
            ->assertOk()
            ->assertJsonPath('state.googleEvents', [])
            ->assertJsonPath('result.google.fromGoogle', true);
        $this->postJson(route('api.mobile.commands'), ['name' => 'scheduleAppointment', 'args' => [$input]])
            ->assertStatus(409);

        $this->assertSame(Appointment::query()->sole()->id, $event->fresh()->appointment_id);
    }

    public function test_an_event_can_be_set_aside_from_the_app_but_not_by_viewers(): void
    {
        $event = $this->waiting($this->connect(User::factory()->create()), 'Dentista');

        Sanctum::actingAs(User::factory()->viewer()->create(), ['mobile']);
        $this->postJson(route('api.mobile.commands'), ['name' => 'dismissGoogleEvent', 'args' => [$event->id]])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $this->postJson(route('api.mobile.commands'), ['name' => 'dismissGoogleEvent', 'args' => [$event->id]])->assertOk();

        $this->assertNotNull($event->fresh()->dismissed_at);
    }

    public function test_each_person_only_gets_the_link_to_their_own_copy(): void
    {
        $ana = User::factory()->create();
        $appointment = Appointment::factory()->for($ana, 'responsible')->create();
        GoogleEventCopy::query()->create(['appointment_id' => $appointment->id, 'google_account_id' => $this->connect($ana)->id, 'html_link' => 'https://www.google.com/calendar/event?eid=abc']);

        Sanctum::actingAs($ana, ['mobile']);
        $this->getJson(route('api.mobile.bootstrap'))->assertJsonPath('state.appointments.0.google.link', 'https://www.google.com/calendar/event?eid=abc');

        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $this->getJson(route('api.mobile.bootstrap'))->assertJsonPath('state.appointments.0.google.link', null);
    }

    public function test_the_app_lists_calendars_chooses_one_and_disconnects(): void
    {
        $ana = User::factory()->create();
        $account = $this->connect($ana, ['calendar_id' => 'ana-sef']);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'ana-sef', 'summary' => 'Caderneta SEF'],
                ['id' => 'sef@group.calendar.google.com', 'summary' => 'Atendimentos SEF'],
            ]]),
            '*' => Http::response(status: 204),
        ]);
        Sanctum::actingAs($ana, ['mobile']);

        $this->getJson(route('api.google.calendars'))->assertOk()->assertExactJson(['calendars' => [
            ['id' => 'sef@group.calendar.google.com', 'name' => 'Atendimentos SEF', 'primary' => false],
        ]]);
        $this->putJson(route('api.google.update'), ['calendarId' => 'sef@group.calendar.google.com'])
            ->assertOk()->assertJsonPath('google.calendarName', 'Atendimentos SEF');
        $this->putJson(route('api.google.update'), ['calendarId' => 'ana-sef'])->assertUnprocessable();

        $this->deleteJson(route('api.google.destroy'))->assertOk()->assertJsonPath('google.connected', false);
        $this->assertModelMissing($account);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connect(User $user, array $attributes = []): GoogleAccount
    {
        return GoogleAccount::query()->create([
            'user_id' => $user->id,
            'email' => 'ana.google@gmail.com',
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => implode(' ', GoogleAuthorization::SCOPES),
            ...$attributes,
        ]);
    }

    private function waiting(GoogleAccount $account, string $title): GoogleEvent
    {
        return GoogleEvent::query()->create([
            'google_account_id' => $account->id,
            'event_id' => 'event-'.$title,
            'title' => $title,
            'starts_at' => '2026-10-06 13:00',
            'ends_at' => '2026-10-06 14:00',
        ]);
    }
}
