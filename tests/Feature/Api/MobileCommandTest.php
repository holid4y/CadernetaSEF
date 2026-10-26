<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Appointments\Actions\UndoStatusChange;
use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 12:00');
        $this->member = User::factory()->create(['name' => 'Maria']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesOutsideTheList(): array
    {
        return [
            'php method' => ['__construct'],
            'controller helper' => ['validate'],
            'framework function' => ['system'],
            'case changed' => ['SaveClient'],
        ];
    }

    #[DataProvider('namesOutsideTheList')]
    public function test_only_listed_commands_run(string $name): void
    {
        $this->command($name, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_client_is_registered_and_comes_back_in_the_new_state(): void
    {
        $response = $this->command('saveClient', [[
            'type' => 'organization',
            'name' => 'Padaria Pão Quente Ltda',
            'tradeName' => 'Pão Quente',
            'document' => '11.222.333/0001-81',
            'phone' => '(51) 3594-7788',
            'email' => 'paoquente@example.com',
            'acceptsReminders' => true,
        ], null])->assertOk();

        $client = Client::query()->sole();
        $this->assertSame('Pão Quente', $client->trade_name);
        $this->assertNotNull($client->reminders_consented_at);
        $response->assertJsonPath('result.id', $client->id)
            ->assertJsonPath('result.document', '11222333000181')
            ->assertJsonPath('state.clients.0.id', $client->id);
    }

    public function test_client_errors_point_to_the_fields_of_the_app_form(): void
    {
        Client::factory()->create(['document' => '529.982.247-25']);

        $this->command('saveClient', [[
            'type' => 'individual',
            'name' => 'Outra pessoa',
            'document' => '52998224725',
            'phone' => '123',
        ], null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'Já existe um atendido com este CPF/CNPJ. Busque pelo número completo na lista de atendidos.')
            ->assertJsonPath('errors.phone.0', 'Telefone inválido. Informe o DDD e o número.');
    }

    public function test_editing_a_client_someone_else_just_changed_is_refused(): void
    {
        $client = Client::factory()->create(['name' => 'Joana']);
        $seenAt = $client->updated_at->getTimestampMs();
        $this->travel(5)->seconds();
        $client->update(['name' => 'Joana Lima']);

        $this->command('saveClient', [['type' => 'individual', 'name' => 'Joana L.', 'phone' => '(51) 99812-4410', 'updatedAt' => $seenAt], $client->id])
            ->assertConflict()
            ->assertJsonPath('errors.updatedAt.0', 'Este cadastro foi alterado por outra pessoa. Atualize os dados e tente de novo.');

        $this->assertSame('Joana Lima', $client->fresh()->name);
    }

    public function test_viewers_are_refused_with_403(): void
    {
        $this->actingAsMobile(User::factory()->viewer()->create());

        $this->command('saveClient', [['type' => 'individual', 'name' => 'Teste', 'phone' => '(51) 99812-4410'], null])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seu perfil não tem permissão para fazer isso.');
        $this->command('scheduleAppointment', [[]])->assertForbidden();
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_scheduling_uses_the_same_rules_as_the_web(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->withDocuments(['CPF', 'Comprovante de residência'])->create(['slug' => 'irpf']);

        $response = $this->command('scheduleAppointment', [[
            'clientId' => $client->id,
            'serviceSlug' => 'irpf',
            'responsibleId' => $this->member->id,
            'start' => $this->ms('2026-10-06 13:00'),
            'end' => $this->ms('2026-10-06 14:00'),
            'locationType' => 'remote',
            'location' => 'Videochamada',
        ]])->assertOk();

        $appointment = Appointment::query()->sole();
        $this->assertSame('2026-10-06 13:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame($this->member->id, $appointment->created_by);
        $this->assertSame(['CPF', 'Comprovante de residência'], $appointment->documents->pluck('name')->all());
        $this->assertSame(ActivityType::Created, $appointment->activities()->sole()->event_type);
        $response->assertJsonPath('result.id', $appointment->id)
            ->assertJsonPath('result.serviceSlug', $service->slug)
            ->assertJsonPath('result.start', $this->ms('2026-10-06 13:00'));

        $this->actingAs($this->member)->get("/atendimentos/{$appointment->id}")->assertOk()->assertSee($client->name);
    }

    public function test_a_busy_slot_answers_409_with_the_explanation(): void
    {
        Appointment::factory()->for($this->member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('scheduleAppointment', [$this->bookingInput('2026-10-06 13:30', '2026-10-06 14:30')])
            ->assertConflict()
            ->assertJsonPath('errors.start.0', 'Maria já tem atendimento das 10:00 às 11:00. Escolha outro horário ou outro responsável.');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_an_inactive_service_can_not_be_booked(): void
    {
        Service::factory()->inactive()->create(['slug' => 'antigo']);

        $this->command('scheduleAppointment', [[...$this->bookingInput('2026-10-06 13:00', '2026-10-06 14:00'), 'serviceSlug' => 'antigo']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('serviceSlug');
    }

    public function test_rescheduling_logs_the_old_period(): void
    {
        $appointment = Appointment::factory()->for($this->member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('rescheduleAppointment', [$appointment->id, [
            'start' => $this->ms('2026-10-07 13:00'),
            'end' => $this->ms('2026-10-07 14:00'),
            'responsibleId' => $this->member->id,
            'updatedAt' => $appointment->updated_at->getTimestampMs(),
        ]])->assertOk()->assertJsonPath('result.activities.0.type', 'rescheduled');

        $this->assertSame('2026-10-07 13:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_a_stale_reschedule_is_refused(): void
    {
        $appointment = Appointment::factory()->for($this->member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();
        $seenAt = $appointment->updated_at->getTimestampMs();
        $this->travel(5)->seconds();
        $appointment->update(['location' => 'Sala 2']);

        $this->command('rescheduleAppointment', [$appointment->id, [
            'start' => $this->ms('2026-10-07 13:00'),
            'end' => $this->ms('2026-10-07 14:00'),
            'responsibleId' => $this->member->id,
            'updatedAt' => $seenAt,
        ]])->assertConflict();

        $this->assertSame('2026-10-06 13:00', $appointment->fresh()->starts_at->format('Y-m-d H:i'));
    }

    public function test_status_changes_respect_what_the_person_saw(): void
    {
        $appointment = Appointment::factory()->create();

        $this->command('changeStatus', [$appointment->id, 'confirmed', 'scheduled'])
            ->assertOk()
            ->assertJsonPath('result.status', 'confirmed');
        $this->command('changeStatus', [$appointment->id, 'cancelled', 'scheduled'])
            ->assertConflict()
            ->assertJsonPath('errors.status.0', 'Este atendimento foi alterado por outra pessoa. Atualize os dados e tente de novo.');
        $this->command('changeStatus', [$appointment->id, 'completed'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Um atendimento "Confirmado" não pode passar para "Concluído".');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_documents_end_in_the_state_the_person_chose(): void
    {
        $appointment = Appointment::factory()->create();
        $document = $appointment->documents()->create(['name' => 'CPF', 'sort_order' => 0]);

        $this->command('toggleDocument', [$appointment->id, $document->id, true])->assertOk()->assertJsonPath('result.id', $document->id);
        $this->command('toggleDocument', [$appointment->id, $document->id, true])->assertOk();
        $this->assertNotNull($document->fresh()->received_at);

        $this->command('toggleDocument', [$appointment->id, $document->id])->assertOk()->assertJsonPath('result.receivedAt', null);
    }

    public function test_a_document_of_another_appointment_is_not_found(): void
    {
        $appointment = Appointment::factory()->create();
        $foreign = Appointment::factory()->create()->documents()->create(['name' => 'CPF', 'sort_order' => 0]);

        $this->command('toggleDocument', [$appointment->id, $foreign->id, true])
            ->assertNotFound()
            ->assertJsonPath('message', 'Registro não encontrado. Atualize os dados e tente de novo.');
    }

    public function test_a_reminder_sent_from_the_app_reaches_the_client(): void
    {
        Notification::fake();
        $client = Client::factory()->create(['email' => 'joana@example.com', 'accepts_reminders' => true]);
        $appointment = Appointment::factory()->for($client)->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('sendReminder', [$appointment->id])->assertOk()->assertJsonPath('result', 'joana@example.com');

        Notification::assertSentTo($client, AppointmentReminder::class);
        $this->assertNotNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_a_reminder_without_consent_explains_why(): void
    {
        Notification::fake();
        $appointment = Appointment::factory()->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('sendReminder', [$appointment->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reminder.0', 'O atendido não autorizou lembretes.');

        Notification::assertNothingSent();
    }

    public function test_a_reminder_refused_by_the_mail_server_is_not_reported_as_sent(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9, 'mail.mailers.smtp.timeout' => 2]);
        $client = Client::factory()->create(['email' => 'joana@example.com', 'accepts_reminders' => true]);
        $appointment = Appointment::factory()->for($client)->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('sendReminder', [$appointment->id])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'O servidor de e-mail não aceitou o lembrete agora. Tente novamente em alguns minutos.');

        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_reminders_stay_off_until_the_server_is_ready_to_send_them(): void
    {
        Notification::fake();
        config(['mail.reminders_enabled' => false]);
        $client = Client::factory()->create(['email' => 'joana@example.com', 'accepts_reminders' => true]);
        $appointment = Appointment::factory()->for($client)->between('2026-10-06 10:00', '2026-10-06 11:00')->create();

        $this->command('sendReminder', [$appointment->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reminder.0', 'O envio de lembretes por e-mail está desligado neste servidor. Use o WhatsApp ou o e-mail do seu aparelho.');
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_undo_restores_the_previous_status_from_the_history(): void
    {
        $appointment = Appointment::factory()->for($this->member, 'responsible')->create();
        $this->command('changeStatus', [$appointment->id, 'cancelled', 'scheduled'])->assertOk();

        $this->command('restoreAppointment', [['id' => $appointment->id, 'status' => 'scheduled', 'notes' => 'ignorado']])
            ->assertOk()
            ->assertJsonPath('result', null);

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame(
            [['status' => 'cancelled'], ['status' => 'scheduled']],
            [$appointment->activities()->first()->old_values, $appointment->activities()->first()->new_values],
        );
        $this->assertSame(2, $appointment->activities()->count());
    }

    public function test_undo_is_refused_when_it_is_no_longer_the_latest_change(): void
    {
        $appointment = Appointment::factory()->for($this->member, 'responsible')->create();
        $this->command('changeStatus', [$appointment->id, 'confirmed'])->assertOk();

        $this->actingAsMobile(User::factory()->create());
        $this->command('restoreAppointment', [['id' => $appointment->id, 'status' => 'scheduled']])->assertConflict();

        $this->actingAsMobile($this->member);
        $this->travel(UndoStatusChange::WINDOW_MINUTES + 1)->minutes();
        $this->command('restoreAppointment', [['id' => $appointment->id, 'status' => 'scheduled']])
            ->assertConflict()
            ->assertJsonPath('errors.status.0', 'Não é mais possível desfazer: o atendimento mudou depois disso. Atualize os dados.');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);
    }

    public function test_undoing_a_cancellation_checks_the_freed_slot(): void
    {
        $appointment = Appointment::factory()->for($this->member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();
        $this->command('changeStatus', [$appointment->id, 'cancelled'])->assertOk();
        Appointment::factory()->for($this->member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();

        $this->command('restoreAppointment', [['id' => $appointment->id, 'status' => 'scheduled']])->assertConflict();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_admins_manage_the_checklist_from_the_app(): void
    {
        $service = Service::factory()->withDocuments(['CPF', 'Comprovante'])->create(['slug' => 'irpf']);
        $booked = Appointment::factory()->for($service)->create();
        $booked->documents()->create(['name' => 'CPF', 'sort_order' => 0]);
        $this->actingAsMobile(User::factory()->admin()->create());

        $this->command('addServiceDocument', ['irpf', 'Informe de rendimentos'])->assertOk()->assertJsonPath('result', null);
        $this->command('removeServiceDocument', ['irpf', 0, 'CPF'])->assertOk();
        $this->command('removeServiceDocument', ['irpf', 0, 'CPF'])->assertConflict();
        $this->command('setServiceActive', ['irpf', false])->assertOk()->assertJsonPath('state.services.0.active', false);

        $service->refresh();
        $this->assertSame(['Comprovante', 'Informe de rendimentos'], $service->documents->pluck('name')->all());
        $this->assertFalse($service->active);
        $this->assertSame(['CPF'], $booked->documents()->pluck('name')->all());
    }

    public function test_members_can_not_change_the_catalog(): void
    {
        Service::factory()->create(['slug' => 'irpf']);

        $this->command('setServiceActive', ['irpf', false])->assertForbidden();
        $this->assertTrue(Service::query()->sole()->active);
    }

    public function test_changes_from_the_app_are_audited_under_the_person(): void
    {
        $this->command('saveClient', [['type' => 'individual', 'name' => 'Joana', 'phone' => '(51) 99812-4410'], null])->assertOk();

        $audit = DB::table('data_audits')->where('table_name', 'clients')->sole();
        $this->assertSame($this->member->id, (int) $audit->app_user_id);
    }

    public function test_malformed_arguments_are_refused(): void
    {
        $this->actingAsMobile($this->member);
        $this->postJson('/api/v1/mobile/commands', ['name' => 'changeStatus', 'args' => 'texto'])->assertUnprocessable();
        $this->command('changeStatus', ['um', 'confirmed'])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->command('scheduleAppointment', [[...$this->bookingInput('2026-10-06 13:00', '2026-10-06 14:00'), 'start' => $this->ms('2030-01-01 10:00')]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.start.0', 'Escolha uma data entre um ano atrás e dois anos à frente.');
    }

    private function command(string $name, array $args): TestResponse
    {
        if ($this->app['auth']->guard('sanctum')->user() === null) {
            $this->actingAsMobile($this->member);
        }

        return $this->postJson('/api/v1/mobile/commands', ['name' => $name, 'args' => $args]);
    }

    private function actingAsMobile(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['mobile']);
    }

    private function bookingInput(string $start, string $end): array
    {
        return [
            'clientId' => Client::factory()->create()->id,
            'serviceSlug' => Service::factory()->create()->slug,
            'responsibleId' => $this->member->id,
            'start' => $this->ms($start),
            'end' => $this->ms($end),
        ];
    }

    private function ms(string $utc): int
    {
        return Carbon::parse($utc, 'UTC')->getTimestampMs();
    }
}
