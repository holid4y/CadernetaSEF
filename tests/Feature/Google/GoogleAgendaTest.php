<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleAuthorization;
use App\Livewire\Appointments\CreateAppointment;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\GoogleEventCopy;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
        Queue::fake();
        $this->travelTo('2026-10-05 12:00');
    }

    public function test_events_waiting_in_google_show_up_in_the_agenda_to_be_completed(): void
    {
        $ana = User::factory()->create(['name' => 'Ana']);
        $event = $this->waiting($ana, 'Joana - IRPF');

        $this->actingAs($ana)->getJson(route('agenda.events', ['start' => '2026-10-05T00:00:00Z', 'end' => '2026-10-12T00:00:00Z']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => "google-{$event->id}",
                'title' => 'Joana - IRPF',
                'start' => '2026-10-06T10:00:00',
                'url' => route('appointments.create', ['google' => $event->id]),
                'editable' => false,
            ])
            ->assertJsonPath('0.extendedProps.responsible', 'Ana');
    }

    public function test_viewers_see_waiting_events_but_can_not_open_them(): void
    {
        $this->waiting(User::factory()->create(), 'Joana - IRPF');

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson(route('agenda.events', ['start' => '2026-10-05T00:00:00Z', 'end' => '2026-10-12T00:00:00Z']))
            ->assertOk()
            ->assertJsonPath('0.url', null);
    }

    public function test_the_responsible_filter_applies_to_events_from_google(): void
    {
        $this->waiting(User::factory()->create(), 'De outra pessoa');
        $bruno = User::factory()->create();

        $this->actingAs($bruno)
            ->getJson(route('agenda.events', ['start' => '2026-10-05T00:00:00Z', 'end' => '2026-10-12T00:00:00Z', 'responsible' => $bruno->id]))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_completing_an_event_turns_it_into_an_appointment_once(): void
    {
        $ana = User::factory()->create();
        $event = $this->waiting($ana, 'Joana - IRPF', 'Sala 2');
        $client = Client::factory()->create();
        $service = Service::factory()->create();
        $this->actingAs($ana);

        $form = Livewire::test(CreateAppointment::class, ['googleEventId' => $event->id])
            ->assertSet('date', '2026-10-06')
            ->assertSet('start_time', '10:00')
            ->assertSet('end_time', '11:00')
            ->assertSet('location', 'Sala 2')
            ->assertSee('Joana - IRPF');

        $form->call('selectClient', $client->id)->set('service_id', $service->id)->call('save')->assertHasNoErrors();

        $appointment = Appointment::query()->sole();
        $this->assertSame($appointment->id, $event->fresh()->appointment_id);

        Livewire::test(CreateAppointment::class, ['googleEventId' => $event->id])
            ->assertSet('google_event_id', null);
    }

    public function test_an_event_that_is_not_an_appointment_can_be_set_aside(): void
    {
        $ana = User::factory()->create();
        $event = $this->waiting($ana, 'Dentista');

        Livewire::actingAs($ana)->test(CreateAppointment::class, ['googleEventId' => $event->id])
            ->call('dismissGoogleEvent')
            ->assertRedirect(route('agenda'));

        $this->assertNotNull($event->fresh()->dismissed_at);
        $this->getJson(route('agenda.events', ['start' => '2026-10-05T00:00:00Z', 'end' => '2026-10-12T00:00:00Z']))->assertJsonCount(0);
    }

    public function test_a_title_from_google_is_shown_as_text(): void
    {
        $ana = User::factory()->create();
        $event = $this->waiting($ana, '<img src=x onerror=alert(1)>');

        $this->actingAs($ana)->get(route('appointments.create', ['google' => $event->id]))
            ->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    public function test_the_agenda_offers_to_connect_and_then_to_open_google(): void
    {
        $ana = User::factory()->create();

        $this->actingAs($ana)->get(route('agenda'))->assertSee(__('google.continue'))->assertSee(route('google.connect'));

        $this->connect($ana);
        $this->get(route('agenda'))->assertDontSee(__('google.continue'))->assertSee(__('google.open'));

        config(['services.google.client_id' => null]);
        $this->get(route('agenda'))->assertDontSee(__('google.open'));
    }

    public function test_the_appointment_links_to_its_copy_in_google(): void
    {
        $ana = User::factory()->create();
        $account = $this->connect($ana);
        $appointment = Appointment::factory()->for($ana, 'responsible')->create();
        GoogleEventCopy::query()->create(['appointment_id' => $appointment->id, 'google_account_id' => $account->id, 'html_link' => 'https://www.google.com/calendar/event?eid=abc']);

        $this->actingAs($ana)->get(route('appointments.show', $appointment))
            ->assertOk()
            ->assertSee('https://www.google.com/calendar/event?eid=abc', false)
            ->assertSee(__('google.open'));
    }

    private function waiting(User $owner, string $title, ?string $location = null): GoogleEvent
    {
        return GoogleEvent::query()->create([
            'google_account_id' => $this->connect($owner)->id,
            'event_id' => 'event-'.$title,
            'title' => $title,
            'location' => $location,
            'starts_at' => '2026-10-06 13:00',
            'ends_at' => '2026-10-06 14:00',
        ]);
    }

    private function connect(User $user): GoogleAccount
    {
        return GoogleAccount::query()->firstOrCreate(['user_id' => $user->id], [
            'email' => "{$user->id}@gmail.com",
            'refresh_token' => 'refresh-token',
            'scopes' => implode(' ', GoogleAuthorization::SCOPES),
            'calendar_id' => 'sef',
        ]);
    }
}
