<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_uses_the_local_day(): void
    {
        // 21:00 on Monday in São Paulo; 00:30 Tuesday UTC is still "today".
        $this->travelTo('2026-10-06 00:00');
        Appointment::factory()->for(Client::factory()->create(['name' => 'Hoje à Noite']))->between('2026-10-06 00:30', '2026-10-06 01:30')->create();
        Appointment::factory()->for(Client::factory()->create(['name' => 'Amanhã Cedo']))->between('2026-10-06 12:00', '2026-10-06 13:00')->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSeeInOrder(['Atendimentos de hoje', 'Hoje à Noite', 'Próximos 7 dias', 'Amanhã Cedo']);
    }

    public function test_completed_appointments_of_the_month_are_counted(): void
    {
        // 22:00 on 31/10 in São Paulo still belongs to October.
        $this->travelTo('2026-11-10 12:00');
        Appointment::factory()->status(AppointmentStatus::Completed)->between('2026-11-03 13:00', '2026-11-03 14:00')->create();
        Appointment::factory()->status(AppointmentStatus::Completed)->between('2026-11-01 01:00', '2026-11-01 02:00')->create();
        Appointment::factory()->status(AppointmentStatus::Cancelled)->between('2026-11-04 13:00', '2026-11-04 14:00')->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['completed'] === 1)
            ->assertSee('concluídos este mês')
            ->assertDontSee('documentos pendentes');
    }

    public function test_cancelled_appointments_are_not_listed(): void
    {
        $this->travelTo('2026-10-05 12:00');
        Appointment::factory()
            ->for(Client::factory()->create(['name' => 'Desmarcou']))
            ->status(AppointmentStatus::Cancelled)
            ->between('2026-10-05 15:00', '2026-10-05 16:00')
            ->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertDontSee('Desmarcou');
    }

    public function test_each_appointment_expands_in_place_and_still_links_to_its_page(): void
    {
        $this->travelTo('2026-10-05 12:00');
        $appointment = Appointment::factory()
            ->for(Client::factory()->create(['phone' => '(51) 99999-1234']))
            ->between('2026-10-05 15:00', '2026-10-05 16:00')
            ->create();
        $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee('aria-controls="appointment-'.$appointment->id.'-details"', escape: false)
            ->assertSee('Informe de rendimentos')
            ->assertSee('tel:51999991234', escape: false)
            ->assertSee(route('appointments.show', $appointment));
    }
}
