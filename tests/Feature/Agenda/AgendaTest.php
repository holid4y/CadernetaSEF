<?php

declare(strict_types=1);

namespace Tests\Feature\Agenda;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_opens_the_agenda_but_only_writers_see_the_create_button(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/agenda')
            ->assertOk()
            ->assertSee('Novo atendimento');

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/agenda')
            ->assertOk()
            ->assertDontSee('Novo atendimento');
    }

    public function test_the_page_tells_the_calendar_which_timezone_is_today(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/agenda')
            ->assertSee('data-timezone="America/Sao_Paulo"', escape: false);
    }

    public function test_events_are_sent_as_sao_paulo_wall_times(): void
    {
        $appointment = Appointment::factory()->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        $this->actingAs(User::factory()->create())
            ->getJson('/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $appointment->id)
            ->assertJsonPath('0.start', '2026-10-05T10:00:00')
            ->assertJsonPath('0.end', '2026-10-05T11:00:00')
            ->assertJsonPath('0.title', $appointment->client->name);
    }

    public function test_the_requested_range_is_read_as_local_time(): void
    {
        // 22:30 on Sunday in São Paulo is already Monday in UTC. A week that
        // starts on Monday must not include it.
        Appointment::factory()->between('2026-10-05 01:30', '2026-10-05 02:30')->create();

        $this->actingAs(User::factory()->create())
            ->getJson('/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z')
            ->assertJsonCount(0);
    }

    public function test_events_can_be_filtered_by_responsible(): void
    {
        $maria = User::factory()->create();
        Appointment::factory()->for($maria, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();
        Appointment::factory()->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->actingAs($maria)
            ->getJson("/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z&responsible={$maria->id}")
            ->assertJsonCount(1);
    }

    public function test_cancelled_appointments_are_hidden_unless_asked_for(): void
    {
        Appointment::factory()->status(AppointmentStatus::Cancelled)->between('2026-10-05 13:00', '2026-10-05 14:00')->create();
        $member = User::factory()->create();
        $range = 'start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z';

        $this->actingAs($member)->getJson("/agenda/eventos?{$range}")->assertJsonCount(0);
        $this->actingAs($member)->getJson("/agenda/eventos?{$range}&cancelled=1")->assertJsonCount(1);
    }

    public function test_viewers_receive_events_they_can_not_drag(): void
    {
        Appointment::factory()->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z')
            ->assertJsonPath('0.editable', false);
    }

    public function test_dragging_an_event_reschedules_it(): void
    {
        $member = User::factory()->create();
        $appointment = Appointment::factory()->for($member, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        $this->actingAs($member)
            ->patchJson("/atendimentos/{$appointment->id}/horario", [
                'starts_at' => '2026-10-06T15:00:00',
                'ends_at' => '2026-10-06T16:30:00',
            ])
            ->assertNoContent();

        $this->assertSame('2026-10-06 18:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_dragging_onto_a_busy_slot_returns_the_conflict_message(): void
    {
        $member = User::factory()->create(['name' => 'Maria']);
        $appointment = Appointment::factory()->for($member, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();
        Appointment::factory()->for($member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->actingAs($member)
            ->patchJson("/atendimentos/{$appointment->id}/horario", [
                'starts_at' => '2026-10-06T10:30:00',
                'ends_at' => '2026-10-06T11:30:00',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.starts_at.0', 'Maria já tem atendimento das 10:00 às 11:00. Escolha outro horário ou outro responsável.');
    }

    public function test_viewers_can_not_reschedule(): void
    {
        $appointment = Appointment::factory()->create();

        $this->actingAs(User::factory()->viewer()->create())
            ->patchJson("/atendimentos/{$appointment->id}/horario", [
                'starts_at' => '2026-10-06T15:00:00',
                'ends_at' => '2026-10-06T16:00:00',
            ])
            ->assertForbidden();
    }

    public function test_lists_where_dates_belong_are_refused_without_an_error_page(): void
    {
        $member = User::factory()->create();
        $appointment = Appointment::factory()->for($member, 'responsible')->create();

        $this->actingAs($member)
            ->getJson('/agenda/eventos?start[]=x&end=2026-10-12T00:00:00Z')
            ->assertUnprocessable();

        $this->actingAs($member)
            ->patchJson("/atendimentos/{$appointment->id}/horario", ['starts_at' => ['x'], 'ends_at' => '2026-10-06T16:30:00'])
            ->assertUnprocessable();
    }

    public function test_browsing_the_agenda_does_not_use_up_the_drag_limit(): void
    {
        $member = User::factory()->create();
        $appointment = Appointment::factory()->for($member, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();
        $this->actingAs($member);

        foreach (range(1, 40) as $view) {
            $this->getJson('/agenda/eventos?start=2026-10-05T00:00:00Z&end=2026-10-12T00:00:00Z')->assertOk();
        }

        $this->patchJson("/atendimentos/{$appointment->id}/horario", [
            'starts_at' => '2026-10-06T15:00:00',
            'ends_at' => '2026-10-06T16:00:00',
        ])->assertNoContent();
    }
}
