<?php

declare(strict_types=1);

namespace Tests\Feature\Appointments;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Livewire\Appointments\AppointmentDetails;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AppointmentDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_appointment_to_every_role(): void
    {
        $appointment = Appointment::factory()->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        $this->actingAs(User::factory()->viewer()->create())
            ->get("/atendimentos/{$appointment->id}")
            ->assertOk()
            ->assertSee($appointment->client->name)
            ->assertSee('segunda-feira')
            ->assertDontSee('Confirmar presença');
    }

    public function test_members_move_the_appointment_through_its_statuses(): void
    {
        $this->actingAs(User::factory()->create());
        $appointment = Appointment::factory()->create();

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->assertSee('Confirmar presença')
            ->call('changeStatus', 'confirmed')
            ->assertSee('Status alterado para "Confirmado".')
            ->assertSee('Iniciar atendimento');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_viewers_can_not_change_anything(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $appointment = Appointment::factory()->create();

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->call('changeStatus', 'cancelled')
            ->assertForbidden();
    }

    public function test_documents_are_checked_off(): void
    {
        $this->actingAs(User::factory()->create());
        $appointment = Appointment::factory()->today()->create();
        $document = $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->assertSee('0 de 1 conferidos')
            ->assertSeeHtml('role="checkbox"')
            ->call('markDocument', $document->id, true)
            ->assertSee('1 de 1 conferidos');

        $this->assertNotNull($document->fresh()->received_at);
    }

    public function test_documents_wait_for_the_day_of_the_appointment(): void
    {
        $this->actingAs(User::factory()->create());
        $appointment = Appointment::factory()->create();
        $document = $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->assertSee('A pessoa traz os documentos no dia')
            ->assertDontSeeHtml('role="checkbox"')
            ->call('markDocument', $document->id, true)
            ->assertHasErrors('documents');

        $this->assertNull($document->fresh()->received_at);
    }

    public function test_repeated_clicks_end_in_the_state_the_person_last_saw(): void
    {
        $this->actingAs(User::factory()->create());
        $appointment = Appointment::factory()->today()->create();
        $document = $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->call('markDocument', $document->id, true)
            ->call('markDocument', $document->id, true)
            ->call('markDocument', $document->id, false);

        $this->assertNull($document->fresh()->received_at);
    }

    public function test_viewers_see_the_checklist_without_being_able_to_change_it(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $appointment = Appointment::factory()->create();
        $document = $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->assertSee('Informe de rendimentos')
            ->assertDontSeeHtml('role="checkbox"')
            ->call('markDocument', $document->id, true)
            ->assertForbidden();
    }

    public function test_time_and_responsible_change_together_without_false_conflicts(): void
    {
        $maria = User::factory()->create(['name' => 'Maria']);
        $joao = User::factory()->create(['name' => 'João']);
        $this->actingAs($maria);

        $appointment = Appointment::factory()->for($maria, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();
        // João is busy at the old time, free at the new one.
        Appointment::factory()->for($joao, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->call('editSchedule')
            ->set('start_time', '15:00')
            ->set('end_time', '16:00')
            ->set('responsible_user_id', $joao->id)
            ->call('saveSchedule')
            ->assertHasNoErrors()
            ->assertSee('Maria → João');

        $fresh = $appointment->fresh();
        $this->assertSame($joao->id, $fresh->responsible_user_id);
        $this->assertSame('18:00', $fresh->starts_at->format('H:i'));
    }
}
