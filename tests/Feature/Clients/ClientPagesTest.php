<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Livewire\Clients\ClientEditor;
use App\Livewire\Clients\ClientIndex;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_list_searches_by_name_phone_and_document(): void
    {
        Client::factory()->create(['name' => 'Ana Paula', 'phone' => '(51) 98888-1234', 'document' => '123.456.789-00']);
        // Fixed contact data: a random email could contain "ana" and match.
        Client::factory()->create(['name' => 'Bruno Costa', 'email' => 'bruno@example.com', 'phone' => '(51) 97777-0000']);

        Livewire::test(ClientIndex::class)
            ->set('search', 'ana')->assertSee('Ana Paula')->assertDontSee('Bruno Costa')
            ->set('search', '98888 12')->assertSee('Ana Paula')->assertDontSee('Bruno Costa')
            ->set('search', '12345678900')->assertSee('Ana Paula');
    }

    public function test_a_cnpj_with_letters_is_saved_and_found_however_it_is_typed(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ClientEditor::class)
            ->set('form.type', 'organization')
            ->set('form.name', 'Cooperativa Nova Esperança')
            ->set('form.phone', '(51) 3595-0000')
            ->set('form.document', '12.ABC.345/01DE-35')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(ClientIndex::class)
            ->set('search', '12abc34501de35')->assertSee('Cooperativa Nova Esperança')
            ->set('search', '12.ABC.345/01DE-36')->assertDontSee('Cooperativa Nova Esperança');
    }

    public function test_a_search_with_like_wildcards_is_taken_literally(): void
    {
        Client::factory()->create(['name' => 'Ana Paula']);

        Livewire::test(ClientIndex::class)
            ->set('search', '%')
            ->assertDontSee('Ana Paula');
    }

    public function test_an_organization_is_registered(): void
    {
        Livewire::test(ClientEditor::class)
            ->set('form.type', 'organization')
            ->set('form.name', 'Associação Amigos do Bairro')
            ->set('form.trade_name', 'Amigos do Bairro')
            ->set('form.email', 'contato@amigos.org')
            ->call('save')
            ->assertHasNoErrors();

        $client = Client::query()->sole();
        $this->assertTrue($client->isOrganization());
        $this->assertSame('Amigos do Bairro', $client->trade_name);
        $this->assertFalse($client->accepts_reminders);
    }

    public function test_editing_keeps_the_same_record(): void
    {
        $client = Client::factory()->create(['name' => 'Nome Antigo']);

        Livewire::test(ClientEditor::class, ['client' => $client])
            ->assertSet('form.name', 'Nome Antigo')
            ->set('form.name', 'Nome Novo')
            ->call('save')
            ->assertRedirect(route('clients.show', $client));

        $this->assertSame('Nome Novo', $client->fresh()->name);
        $this->assertSame(1, Client::query()->count());
    }

    public function test_the_client_page_lists_upcoming_and_past_appointments(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $client = Client::factory()->create();
        Appointment::factory()->for($client)->between('2026-10-01 13:00', '2026-10-01 14:00')->create();
        Appointment::factory()->for($client)->between('2026-10-20 13:00', '2026-10-20 14:00')->create();

        $this->get("/atendidos/{$client->id}")
            ->assertOk()
            ->assertSeeInOrder(['Próximos atendimentos', '20/10', 'Histórico', '01/10']);
    }

    public function test_viewers_see_clients_but_can_not_edit_them(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->viewer()->create());

        $this->get("/atendidos/{$client->id}")->assertOk()->assertDontSee('Editar cadastro');
        $this->get("/atendidos/{$client->id}/editar")->assertForbidden();
        $this->get('/atendidos/novo')->assertForbidden();
    }

    public function test_viewers_do_not_get_documents_or_notes_on_the_web_either(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $client = Client::factory()->create(['name' => 'Ana Paula', 'document' => '529.982.247-25', 'notes' => 'Prefere contato pela manhã']);
        $appointment = Appointment::factory()->for($client)->between('2026-10-15 13:00', '2026-10-15 14:00')->create(['notes' => 'Trazer os recibos de 2025']);
        $private = ['529.982.247-25', 'Prefere contato pela manhã', 'Trazer os recibos de 2025'];

        $this->actingAs(User::factory()->viewer()->create());
        $this->get("/atendidos/{$client->id}")->assertOk()->assertSee('Ana Paula')->assertDontSee($private);
        $this->get("/atendimentos/{$appointment->id}")->assertOk()->assertDontSee($private);
        Livewire::test(ClientIndex::class)->assertSee('Ana Paula')->assertDontSee($private);

        $this->actingAs(User::factory()->create());
        $this->get("/atendidos/{$client->id}")->assertSee($private);
        $this->get("/atendimentos/{$appointment->id}")->assertSee('Trazer os recibos de 2025');
        Livewire::test(ClientIndex::class)->assertSee('529.982.247-25');
    }

    public function test_each_client_expands_with_the_next_and_recent_appointments(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $client = Client::factory()->create(['name' => 'Ana Paula']);
        Appointment::factory()->for($client)->between('2026-10-01 13:00', '2026-10-01 14:00')->create();
        $next = Appointment::factory()->for($client)->between('2026-10-15 13:00', '2026-10-15 14:00')->create();
        Appointment::factory()->for($client)->between('2026-10-20 13:00', '2026-10-20 14:00')->create();

        Livewire::test(ClientIndex::class)
            ->assertSeeHtml('aria-controls="client-'.$client->id.'-details"')
            ->assertSeeHtml(route('appointments.show', $next))
            ->assertSee('01/10/2026')
            ->assertSeeHtml(route('clients.show', $client));
    }

    public function test_the_next_appointment_ignores_the_past_and_cancelled_ones(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $client = Client::factory()->create();
        Appointment::factory()->for($client)->between('2026-10-01 13:00', '2026-10-01 14:00')->create();
        Appointment::factory()->for($client)->status(AppointmentStatus::Cancelled)->between('2026-10-12 13:00', '2026-10-12 14:00')->create();
        $next = Appointment::factory()->for($client)->between('2026-10-15 13:00', '2026-10-15 14:00')->create();

        $this->assertTrue($client->nextAppointment->is($next));
    }
}
