<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\ReminderSchedule;
use App\Livewire\Settings\ReminderSettings;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AppointmentConfirmation;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AppointmentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-10-05 12:00');
        $this->member = User::factory()->create();
    }

    public function test_a_confirmation_the_mail_server_refused_does_not_count_as_the_reminder(): void
    {
        $appointment = Appointment::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'reminder_sent_at' => now(),
        ]);

        (new AppointmentConfirmation($appointment))->failed(new RuntimeException('refused'));

        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_booking_e_mails_a_confirmation_that_announces_the_reminder(): void
    {
        $appointment = $this->book('2026-10-12 13:00');

        Notification::assertSentTo($appointment->client, AppointmentConfirmation::class, function (AppointmentConfirmation $mail) use ($appointment): bool {
            $text = implode("\n", $mail->toMail($appointment->client)->introLines);

            return ! $mail->moved
                && str_contains($text, 'Seu atendimento no projeto foi marcado.')
                && str_contains($text, 'Você também vai receber um lembrete 2 dias antes.')
                && $mail->toMail($appointment->client)->subject === 'Atendimento marcado para 12/10';
        });
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_nothing_is_sent_without_consent_or_with_e_mail_switched_off(): void
    {
        $withoutConsent = $this->book('2026-10-12 13:00', ['accepts_reminders' => false]);

        config(['mail.reminders_enabled' => false]);
        $switchedOff = $this->book('2026-10-13 13:00');

        Notification::assertNotSentTo([$withoutConsent->client, $switchedOff->client], AppointmentConfirmation::class);
    }

    public function test_a_booking_inside_the_reminder_window_is_not_reminded_twice(): void
    {
        $appointment = $this->book('2026-10-06 13:00');

        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
        $this->artisan('appointments:send-reminders');

        Notification::assertSentToTimes($appointment->client, AppointmentConfirmation::class, 1);
        Notification::assertNotSentTo($appointment->client, AppointmentReminder::class);
    }

    public function test_the_reminder_goes_out_the_chosen_number_of_days_before(): void
    {
        $appointment = $this->book('2026-10-08 11:00');

        $this->artisan('appointments:send-reminders');
        Notification::assertNotSentTo($appointment->client, AppointmentReminder::class);

        Setting::query()->create(['key' => ReminderSchedule::KEY, 'value' => '3']);
        $this->artisan('appointments:send-reminders');
        Notification::assertSentTo($appointment->client, AppointmentReminder::class);
    }

    public function test_moving_the_time_e_mails_the_new_one_and_a_stale_confirmation_is_dropped(): void
    {
        $appointment = $this->book('2026-10-12 13:00');
        $first = null;
        Notification::assertSentTo($appointment->client, AppointmentConfirmation::class, function (AppointmentConfirmation $mail) use (&$first): bool {
            $first = $mail;

            return true;
        });

        app(RescheduleAppointment::class)->handle($this->member, $appointment, Carbon::parse('2026-10-13 13:00', 'UTC'), Carbon::parse('2026-10-13 14:00', 'UTC'));

        Notification::assertSentTo($appointment->client, AppointmentConfirmation::class, fn (AppointmentConfirmation $mail): bool => $mail->moved
            && $mail->shouldSend($appointment->client, 'mail'));
        $this->assertFalse($first->shouldSend($appointment->client, 'mail'));
    }

    public function test_changing_only_the_responsible_sends_nothing_new(): void
    {
        $appointment = $this->book('2026-10-12 13:00');

        app(RescheduleAppointment::class)->handle($this->member, $appointment, $appointment->starts_at, $appointment->ends_at, User::factory()->create());

        Notification::assertSentToTimes($appointment->client, AppointmentConfirmation::class, 1);
    }

    public function test_admins_choose_the_number_of_days(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ReminderSettings::class)
            ->assertSet('days', 2)
            ->set('days', 0)->call('save')->assertHasErrors('days')
            ->set('days', 15)->call('save')->assertHasErrors('days')
            ->set('days', 3)->call('save')->assertHasNoErrors()
            ->assertSee('Lembrete passa a sair 3 dias antes.');

        $this->assertSame(3, ReminderSchedule::daysBefore());
        $this->get('/configuracoes/servicos')->assertOk()->assertSee('Lembretes por e-mail');
    }

    public function test_only_admins_change_the_reminder_days(): void
    {
        $this->actingAs($this->member);

        Livewire::test(ReminderSettings::class)->assertForbidden();
        $this->assertSame(ReminderSchedule::DEFAULT_DAYS, ReminderSchedule::daysBefore());
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function book(string $utcStart, array $client = []): Appointment
    {
        return app(ScheduleAppointment::class)->handle($this->member, [
            'client_id' => Client::factory()->create(['email' => 'joana@example.com', 'accepts_reminders' => true, ...$client])->id,
            'service_id' => Service::factory()->create()->id,
            'responsible_user_id' => $this->member->id,
            'starts_at' => Carbon::parse($utcStart, 'UTC'),
            'ends_at' => Carbon::parse($utcStart, 'UTC')->addHour(),
        ]);
    }
}
