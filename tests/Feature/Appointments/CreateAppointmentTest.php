<?php

declare(strict_types=1);

namespace Tests\Feature\Appointments;

use App\Livewire\Appointments\CreateAppointment;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create(['name' => 'Maria']);
        $this->actingAs($this->member);
    }

    public function test_viewers_can_not_open_the_form(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get('/atendimentos/novo')
            ->assertForbidden();
    }

    public function test_a_mangled_slot_link_opens_the_form_anyway(): void
    {
        $this->get('/atendimentos/novo?inicio=abacaxi&fim=abacaxi')->assertOk();
        $this->get('/atendimentos/novo?inicio[]=1')->assertOk();
    }

    public function test_the_form_starts_from_the_slot_clicked_in_the_agenda(): void
    {
        $this->get('/atendimentos/novo?inicio=2026-10-05T14:00:00&fim=2026-10-05T15:30:00')->assertOk();

        Livewire::test(CreateAppointment::class, ['start' => '2026-10-05T14:00:00', 'end' => '2026-10-05T15:30:00'])
            ->assertSet('date', '2026-10-05')
            ->assertSet('start_time', '14:00')
            ->assertSet('end_time', '15:30')
            ->assertSet('responsible_user_id', $this->member->id);
    }

    public function test_an_appointment_is_scheduled_for_an_existing_client(): void
    {
        $client = Client::factory()->create(['name' => 'Joana Lima']);
        $service = Service::factory()->withDocuments(['Informe de rendimentos'])->create();

        Livewire::test(CreateAppointment::class, ['start' => '2026-10-05T14:00:00'])
            ->set('client_search', 'joana')
            ->assertSee('Joana Lima')
            ->call('selectClient', $client->id)
            ->set('service_id', $service->id)
            ->assertSee('Informe de rendimentos')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('appointments.show', Appointment::query()->sole()));

        $appointment = Appointment::query()->sole();
        $this->assertSame('2026-10-05 17:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame($client->id, $appointment->client_id);
        $this->assertCount(1, $appointment->documents);
    }

    public function test_choosing_a_service_suggests_its_duration(): void
    {
        $service = Service::factory()->create(['default_duration_minutes' => 90]);

        Livewire::test(CreateAppointment::class, ['start' => '2026-10-05T14:00:00'])
            ->set('service_id', $service->id)
            ->assertSet('end_time', '15:30');
    }

    public function test_moving_the_start_keeps_the_duration(): void
    {
        Livewire::test(CreateAppointment::class, ['start' => '2026-10-05T14:00:00', 'end' => '2026-10-05T14:45:00'])
            ->set('start_time', '16:00')
            ->assertSet('end_time', '16:45');
    }

    public function test_a_new_client_can_be_registered_without_leaving_the_form(): void
    {
        Livewire::test(CreateAppointment::class)
            ->set('client_search', 'Carlos Souza')
            ->call('startClientCreation')
            ->assertSet('new_client.name', 'Carlos Souza')
            ->set('new_client.phone', '(51) 99999-0000')
            ->call('saveNewClient')
            ->assertHasNoErrors()
            ->assertSet('client_id', Client::query()->where('name', 'Carlos Souza')->sole()->id);
    }

    public function test_a_new_client_needs_some_way_to_be_contacted(): void
    {
        Livewire::test(CreateAppointment::class)
            ->call('startClientCreation')
            ->set('new_client.name', 'Sem Contato')
            ->call('saveNewClient')
            ->assertHasErrors(['new_client.phone' => 'required_without']);
    }

    public function test_a_conflict_is_shown_on_the_form(): void
    {
        Appointment::factory()->for($this->member, 'responsible')->between('2026-10-05 17:00', '2026-10-05 18:00')->create();

        Livewire::test(CreateAppointment::class, ['start' => '2026-10-05T14:30:00'])
            ->call('selectClient', Client::factory()->create()->id)
            ->set('service_id', Service::factory()->create()->id)
            ->call('save')
            ->assertHasErrors('starts_at')
            ->assertSee('Maria já tem atendimento das 14:00 às 15:00');

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_client_and_service_are_required(): void
    {
        Livewire::test(CreateAppointment::class)
            ->call('save')
            ->assertHasErrors(['client_id' => 'required', 'service_id' => 'required']);
    }

    public function test_a_link_to_an_inactive_or_unknown_client_opens_an_empty_form(): void
    {
        $inactive = Client::factory()->inactive()->create();
        $this->actingAs(User::factory()->create());

        $this->get("/atendimentos/novo?atendido={$inactive->id}")->assertOk();
        $this->get('/atendimentos/novo?atendido=999999')->assertOk();
    }
}
