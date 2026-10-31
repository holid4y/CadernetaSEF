<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\ReminderMessage;
use App\Livewire\Appointments\AppointmentDetails;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo('2026-10-05 12:00');
    }

    public function test_a_consenting_client_is_reminded_the_day_before(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Notification::assertSentTo($appointment->client, AppointmentReminder::class);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_a_reminder_is_never_sent_twice(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');

        $this->artisan('appointments:send-reminders');
        $this->artisan('appointments:send-reminders');

        Notification::assertSentToTimes($appointment->client, AppointmentReminder::class, 1);
    }

    public function test_nobody_is_reminded_without_consent_or_e_mail(): void
    {
        $this->appointmentAt('2026-10-06 10:00', ['accepts_reminders' => false]);
        $this->appointmentAt('2026-10-06 11:00', ['email' => null]);
        $this->appointmentAt('2026-10-06 12:00', ['active' => false]);

        $this->artisan('appointments:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_only_open_appointments_within_a_day_are_reminded(): void
    {
        $this->appointmentAt('2026-10-08 10:00');
        $this->appointmentAt('2026-10-05 10:00');
        $this->appointmentAt('2026-10-06 10:00')->update(['status' => AppointmentStatus::Cancelled]);

        $this->artisan('appointments:send-reminders');

        Notification::assertNothingSent();
    }

    public function test_the_e_mail_lists_the_documents_and_a_signed_link(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);
        $appointment->update(['location' => 'Sala do Empreendedor']);

        $mail = (string) (new AppointmentReminder($appointment->fresh()))->toMail($appointment->client)->render();

        $this->assertStringContainsString('Informe de rendimentos', $mail);
        $this->assertStringContainsString('Sala do Empreendedor', $mail);
        $this->assertStringContainsString('07:00', $mail);
        $this->assertStringContainsString('signature=', $mail);
    }

    public function test_the_link_opens_a_page_that_changes_nothing(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');

        $this->get(ReminderMessage::responseUrl($appointment))
            ->assertOk()
            ->assertSee('Confirmar presença')
            ->assertSee('Não poderei ir');

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_the_client_confirms_through_the_link(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $url = ReminderMessage::responseUrl($appointment);

        $this->post($url, ['answer' => 'confirm'])->assertRedirect($url);

        $activity = $appointment->activities()->first();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
        $this->assertNull($activity->user_id);
        $this->assertSame(ActivityType::StatusChanged, $activity->event_type);
    }

    public function test_the_client_cancels_and_the_team_sees_who_did_it(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');

        $this->post(ReminderMessage::responseUrl($appointment), ['answer' => 'cancel']);

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->actingAs(User::factory()->create())
            ->get(route('appointments.show', $appointment))
            ->assertSee('pelo atendido, no link do lembrete');
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $url = ReminderMessage::responseUrl($appointment);

        $this->get(route('appointments.respond', $appointment))->assertForbidden();
        $this->post(str_replace('signature=', 'signature=x', $url), ['answer' => 'cancel'])->assertForbidden();

        $this->travelTo('2026-10-06 13:01');
        $this->get($url)->assertForbidden();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_a_closed_appointment_can_not_be_changed_through_the_link(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->post(ReminderMessage::responseUrl($appointment), ['answer' => 'confirm'])
            ->assertSessionHas('notice', 'Este atendimento não pode mais ser alterado por aqui.');

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_the_link_no_longer_changes_an_appointment_the_team_has_opened(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $url = ReminderMessage::responseUrl($appointment);
        $appointment->update(['status' => AppointmentStatus::InProgress]);

        $this->get($url)->assertDontSee('Não poderei ir');
        $this->post($url, ['answer' => 'cancel'])
            ->assertSessionHas('notice', 'Este atendimento não pode mais ser alterado por aqui.');

        $this->assertSame(AppointmentStatus::InProgress, $appointment->fresh()->status);
    }

    public function test_an_older_link_stops_working_once_a_moved_appointment_starts(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 18:00');
        $url = ReminderMessage::responseUrl($appointment);
        $appointment->update(['starts_at' => '2026-10-06 10:00', 'ends_at' => '2026-10-06 11:00']);

        $this->travelTo('2026-10-06 10:30');
        $this->post($url, ['answer' => 'cancel']);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_the_team_can_send_it_again_by_hand(): void
    {
        $this->actingAs(User::factory()->create());
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $appointment->forceFill(['reminder_sent_at' => now()->subHour()])->save();

        Livewire::test(AppointmentDetails::class, ['appointment' => $appointment])
            ->call('sendReminder')
            ->assertSee('Lembrete enviado para joana@example.com.');

        Notification::assertSentTo($appointment->client, AppointmentReminder::class);
    }

    public function test_the_whatsapp_message_carries_the_same_content(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00', ['phone' => '(51) 99812-4410']);
        $appointment->documents()->create(['name' => 'CCMEI', 'sort_order' => 0]);

        $url = ReminderMessage::whatsAppUrl($appointment->fresh());
        $text = rawurldecode(parse_url($url, PHP_URL_QUERY));

        $this->assertStringStartsWith('https://wa.me/5551998124410?', $url);
        $this->assertStringContainsString('Olá, Joana!', $text);
        $this->assertStringContainsString('• CCMEI', $text);
        $this->assertStringContainsString('signature=', $text);
    }

    public function test_organisations_are_greeted_by_their_trade_name(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00', [
            'type' => 'organization',
            'name' => 'Associação Comunitária Vila Nova',
            'trade_name' => 'ACVN',
        ]);

        $this->assertSame('Olá, ACVN!', ReminderMessage::lines($appointment)[0]);
    }

    public function test_there_is_no_whatsapp_link_without_a_usable_phone(): void
    {
        $this->assertNull(ReminderMessage::whatsAppUrl($this->appointmentAt('2026-10-06 10:00', ['phone' => '1234'])));
    }

    public function test_a_rescheduled_appointment_is_reminded_of_the_new_time(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $this->artisan('appointments:send-reminders');

        app(RescheduleAppointment::class)->handle(
            User::factory()->create(),
            $appointment->fresh(),
            now()->parse('2026-10-06 11:00'),
            now()->parse('2026-10-06 12:00'),
        );
        $this->artisan('appointments:send-reminders');

        Notification::assertSentToTimes($appointment->client, AppointmentReminder::class, 2);
    }

    public function test_changing_only_the_responsible_does_not_repeat_the_reminder(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $this->artisan('appointments:send-reminders');

        app(RescheduleAppointment::class)->handle(
            User::factory()->create(),
            $appointment->fresh(),
            $appointment->starts_at,
            $appointment->ends_at,
            User::factory()->create(),
        );
        $this->artisan('appointments:send-reminders');

        Notification::assertSentToTimes($appointment->client, AppointmentReminder::class, 1);
    }

    public function test_a_reminder_the_mail_server_kept_refusing_is_tried_again_later(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $this->artisan('appointments:send-reminders');
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);

        (new SendQueuedNotifications(collect([$appointment->client]), new AppointmentReminder($appointment->fresh())))
            ->failed(new RuntimeException('Expected response code 250 but got 421'));

        $this->assertNull($appointment->fresh()->reminder_sent_at);
        $this->artisan('appointments:send-reminders');
        Notification::assertSentToTimes($appointment->client, AppointmentReminder::class, 2);
    }

    public function test_a_reminder_that_never_reached_the_queue_is_tried_again_later(): void
    {
        $appointment = $this->appointmentAt('2026-10-06 10:00');
        $this->mock(ChannelManager::class, fn (MockInterface $mock) => $mock->shouldReceive('send')
            ->once()->andThrow(new RuntimeException('could not reach the queue')));

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_the_reminder_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('appointments:send-reminders');
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function appointmentAt(string $utcStart, array $client = []): Appointment
    {
        return Appointment::factory()
            ->for(Client::factory()->create([
                'name' => 'Joana Lima',
                'email' => 'joana@example.com',
                'accepts_reminders' => true,
                ...$client,
            ]))
            ->for(Service::factory()->create(['name' => 'IRPF']))
            ->between($utcStart, now()->parse($utcStart)->addHour()->format('Y-m-d H:i'))
            ->create();
    }
}
