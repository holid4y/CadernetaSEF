<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Livewire\Appointments\AppointmentDetails;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class MobileStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_snapshot_follows_the_app_model(): void
    {
        $this->travelTo('2026-10-05 12:00');
        config(['services.forms.enabled' => false]);
        $admin = User::factory()->admin()->create(['name' => 'Ana']);
        $service = Service::factory()
            ->for(ServiceCategory::factory()->create(['slug' => 'fiscal']), 'category')
            ->withDocuments(['Informe de rendimentos', 'Recibo da última declaração'])
            ->create(['slug' => 'irpf', 'default_duration_minutes' => 45]);
        $client = Client::factory()->create(['name' => 'Joana Lima', 'document' => '529.982.247-25', 'accepts_reminders' => true]);

        $appointment = app(ScheduleAppointment::class)->handle($admin, [
            'client_id' => $client->id,
            'service_id' => $service->id,
            'responsible_user_id' => $admin->id,
            'starts_at' => Carbon::parse('2026-10-06 13:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-06 14:00', 'UTC'),
            'notes' => 'Trazer a declaração anterior',
        ]);
        app(ChangeAppointmentStatus::class)->handle($admin, $appointment, AppointmentStatus::Confirmed);

        Sanctum::actingAs($admin, ['mobile']);
        $state = $this->getJson('/api/v1/mobile/bootstrap')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('state');

        $this->assertSame(['id' => $admin->id, 'name' => 'Ana', 'email' => $admin->email, 'role' => 'admin', 'active' => true], $state['users'][0]);
        $this->assertSame([
            'slug' => 'irpf',
            'categorySlug' => 'fiscal',
            'name' => $service->name,
            'minutes' => 45,
            'requiresDetails' => false,
            'active' => true,
            'documents' => ['Informe de rendimentos', 'Recibo da última declaração'],
        ], $state['services'][0]);

        $this->assertSame('52998224725', $state['clients'][0]['document']);
        $this->assertSame(Carbon::parse('2026-10-05 12:00')->getTimestampMs(), $state['clients'][0]['consentAt']);

        $item = $state['appointments'][0];
        $this->assertSame($client->id, $item['clientId']);
        $this->assertSame('irpf', $item['serviceSlug']);
        $this->assertSame(Carbon::parse('2026-10-06 13:00', 'UTC')->getTimestampMs(), $item['start']);
        $this->assertSame('confirmed', $item['status']);
        $this->assertSame('Trazer a declaração anterior', $item['notes']);
        $this->assertSame(['Informe de rendimentos', 'Recibo da última declaração'], array_column($item['documents'], 'name'));
        $this->assertNull($item['documents'][0]['receivedAt']);
        $this->assertSame(['created', 'status_changed'], array_column($item['activities'], 'type'));
        $this->assertSame(['status' => 'scheduled'], $item['activities'][1]['old']);
        $this->assertSame($appointment->integration_id, $item['integrationId']);
        $this->assertFalse($item['formsEnabled']);
    }

    public function test_secrets_and_colleagues_addresses_never_leave_the_server(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->admin()->create(['email' => 'colega@agenda.test']);
        $colleague->createToken('celular');

        Sanctum::actingAs($member, ['mobile']);
        $body = $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->getContent();

        $this->assertStringNotContainsString('colega@agenda.test', $body);
        $this->assertStringNotContainsString('$2y$', $body);
        $this->assertStringNotContainsString('remember_token', $body);
        $this->assertStringNotContainsString($colleague->tokens()->sole()->token, $body);
        $this->assertStringContainsString($member->email, $body);
    }

    public function test_viewers_do_not_receive_documents_or_notes(): void
    {
        Client::factory()->create(['document' => '529.982.247-25', 'notes' => 'Situação financeira delicada']);
        Appointment::factory()->create(['notes' => 'Anotação interna']);

        Sanctum::actingAs(User::factory()->viewer()->create(), ['mobile']);
        $state = $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->json('state');

        $this->assertSame([null, null], array_column($state['clients'], 'document'));
        $this->assertSame([null, null], array_column($state['clients'], 'notes'));
        $this->assertNull($state['appointments'][0]['notes']);
        $this->assertNull($state['appointments'][0]['formCreateUrl']);
    }

    public function test_changes_made_on_the_web_reach_the_app(): void
    {
        $member = User::factory()->create(['name' => 'Maria']);
        $appointment = Appointment::factory()->for($member, 'responsible')->between('2026-10-06 13:00', '2026-10-06 14:00')->create();
        $this->travelTo('2026-10-05 12:00');

        Livewire::actingAs($member)
            ->test(AppointmentDetails::class, ['appointment' => $appointment])
            ->call('changeStatus', 'confirmed');
        app(RescheduleAppointment::class)->handle($member, $appointment->fresh(), Carbon::parse('2026-10-07 13:00', 'UTC'), Carbon::parse('2026-10-07 14:00', 'UTC'));

        Sanctum::actingAs($member, ['mobile']);
        $item = $this->getJson('/api/v1/mobile/bootstrap')->json('state.appointments.0');

        $this->assertSame('confirmed', $item['status']);
        $this->assertSame(Carbon::parse('2026-10-07 13:00', 'UTC')->getTimestampMs(), $item['start']);
        $this->assertSame(['status_changed', 'rescheduled'], array_column($item['activities'], 'type'));
        $this->assertSame([
            'start' => Carbon::parse('2026-10-06 13:00', 'UTC')->getTimestampMs(),
            'end' => Carbon::parse('2026-10-06 14:00', 'UTC')->getTimestampMs(),
        ], $item['activities'][1]['old']);
    }

    public function test_a_removed_client_is_still_named_in_the_history(): void
    {
        $appointment = Appointment::factory()->create();
        $appointment->client->delete();

        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $clients = $this->getJson('/api/v1/mobile/bootstrap')->json('state.clients');

        $this->assertSame([$appointment->client_id], array_column($clients, 'id'));
        $this->assertFalse($clients[0]['active']);
    }

    public function test_guests_get_a_clear_401(): void
    {
        $this->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sua sessão expirou. Entre novamente.');
    }
}
