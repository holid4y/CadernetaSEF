<?php

declare(strict_types=1);

namespace Tests\Feature\Appointments;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();
    }

    public function test_scheduling_records_the_author_and_the_creation(): void
    {
        $appointment = $this->schedule();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame($this->member->id, $appointment->created_by);
        $this->assertSame(
            [ActivityType::Created],
            $appointment->activities->pluck('event_type')->all(),
        );
    }

    public function test_the_weekday_is_derived_from_the_local_date(): void
    {
        // 01:00 UTC on Tuesday is still Monday evening in São Paulo.
        $appointment = Appointment::factory()->between('2026-10-06 01:00', '2026-10-06 02:00')->create();

        $this->assertSame('segunda-feira', $appointment->weekday);
        $this->assertArrayNotHasKey('weekday', $appointment->getAttributes());
    }

    public function test_an_appointment_goes_from_scheduled_to_completed(): void
    {
        $appointment = $this->schedule();
        $change = app(ChangeAppointmentStatus::class);

        $change->handle($this->member, $appointment, AppointmentStatus::Confirmed);
        $change->handle($this->member, $appointment, AppointmentStatus::InProgress);
        $change->handle($this->member, $appointment, AppointmentStatus::Completed);

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertCount(4, $appointment->activities()->get());
    }

    public function test_cancelling_keeps_the_record_and_logs_it(): void
    {
        $appointment = $this->schedule();

        app(ChangeAppointmentStatus::class)->handle($this->member, $appointment, AppointmentStatus::Cancelled);

        $this->assertModelExists($appointment);
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame(ActivityType::Cancelled, $appointment->activities()->first()->event_type);
        $this->assertSame($this->member->id, $appointment->fresh()->updated_by);
    }

    public function test_finished_appointments_can_not_change_status(): void
    {
        $appointment = Appointment::factory()->status(AppointmentStatus::Cancelled)->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Um atendimento "Cancelado" não pode passar para "Agendado".');

        app(ChangeAppointmentStatus::class)->handle($this->member, $appointment, AppointmentStatus::Scheduled);
    }

    public function test_rescheduling_changes_the_period_and_keeps_the_old_one_in_the_log(): void
    {
        $appointment = $this->schedule();

        app(RescheduleAppointment::class)->handle(
            $this->member,
            $appointment,
            Carbon::parse('2026-10-07 13:00', 'UTC'),
            Carbon::parse('2026-10-07 14:00', 'UTC'),
        );

        $activity = $appointment->activities()->first();

        $this->assertSame('2026-10-07 13:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame(ActivityType::Rescheduled, $activity->event_type);
        $this->assertSame('2026-10-05T13:00:00+00:00', $activity->old_values['starts_at']);
    }

    public function test_rescheduling_into_its_own_period_is_not_a_conflict(): void
    {
        $appointment = $this->schedule();

        app(RescheduleAppointment::class)->handle(
            $this->member,
            $appointment,
            Carbon::parse('2026-10-05 13:30', 'UTC'),
            Carbon::parse('2026-10-05 14:30', 'UTC'),
        );

        $this->assertSame('13:30', $appointment->fresh()->starts_at->format('H:i'));
    }

    public function test_completed_appointments_can_not_be_rescheduled(): void
    {
        $appointment = Appointment::factory()->status(AppointmentStatus::Completed)->create();

        $this->expectException(ValidationException::class);

        app(RescheduleAppointment::class)->handle($this->member, $appointment, now()->addDay(), now()->addDay()->addHour());
    }

    public function test_reassigning_checks_the_new_responsible_agenda(): void
    {
        $appointment = $this->schedule();
        $busy = User::factory()->create();
        Appointment::factory()->count(5)->for($busy, 'responsible')->between('2026-10-05 13:30', '2026-10-05 14:30')->create();

        $this->expectException(ValidationException::class);

        app(RescheduleAppointment::class)->handle($this->member, $appointment, $appointment->starts_at, $appointment->ends_at, $busy);
    }

    public function test_reassigning_logs_both_responsibles(): void
    {
        $appointment = $this->schedule();
        $colleague = User::factory()->create();

        app(RescheduleAppointment::class)->handle($this->member, $appointment, $appointment->starts_at, $appointment->ends_at, $colleague);

        $activity = $appointment->activities()->first();

        $this->assertSame($colleague->id, $appointment->fresh()->responsible_user_id);
        $this->assertSame(['responsible_user_id' => $this->member->id], $activity->old_values);
        $this->assertSame(['responsible_user_id' => $colleague->id], $activity->new_values);
    }

    public function test_viewers_and_inactive_users_can_not_be_responsible(): void
    {
        foreach ([User::factory()->viewer()->create(), User::factory()->inactive()->create()] as $user) {
            try {
                $this->schedule(responsible: $user);
                $this->fail("{$user->role->value} user was accepted as responsible.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('responsible_user_id', $exception->errors());
            }
        }
    }

    public function test_inactive_services_can_not_be_used_for_new_appointments(): void
    {
        $this->expectException(ValidationException::class);

        $this->schedule(service: Service::factory()->inactive()->create());
    }

    public function test_the_other_service_requires_a_description(): void
    {
        $service = Service::factory()->create(['name' => 'Outros', 'requires_details' => true]);

        try {
            $this->schedule(service: $service);
            $this->fail('An "Outros" appointment without details was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('Descreva a demanda para o serviço "Outros".', $exception->errors()['service_details'][0]);
        }
    }

    private function schedule(?Service $service = null, ?User $responsible = null): Appointment
    {
        return app(ScheduleAppointment::class)->handle($this->member, [
            'client_id' => Client::factory()->create()->id,
            'service_id' => ($service ?? Service::factory()->create())->id,
            'responsible_user_id' => ($responsible ?? $this->member)->id,
            'starts_at' => Carbon::parse('2026-10-05 13:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-05 14:00', 'UTC'),
        ]);
    }
}
