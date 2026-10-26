<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ExternalForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FormIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/v1/integrations/forms/events';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.forms' => [
            'enabled' => true,
            'webhook_secret' => str_repeat('test-only-', 5),
            'allowed_hosts' => ['forms.example.test'],
            'create_url' => 'https://forms.example.test/new?template=one&appointment_id=old#start',
        ]]);
    }

    public function test_appointments_have_a_stable_unique_reference(): void
    {
        $appointment = Appointment::factory()->create();
        $reference = $appointment->integration_id;
        $this->assertTrue(Str::isUuid($reference));
        $appointment->update(['location' => 'Sala 1']);
        $this->assertSame($reference, $appointment->fresh()->integration_id);
        $this->assertNotSame($reference, Appointment::factory()->create()->integration_id);
        $this->assertFalse($appointment->isFillable('integration_id'));
    }

    public function test_migration_backfills_existing_and_soft_deleted_appointments_without_changing_their_data(): void
    {
        Appointment::factory()->create();
        Appointment::factory()->create()->delete();
        $before = DB::table('appointments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        Schema::drop('form_webhook_events');
        Schema::drop('external_forms');
        Schema::table('appointments', function ($table): void {
            $table->dropUnique(['integration_id']);
            $table->dropColumn('integration_id');
        });
        $migration = require database_path('migrations/2026_10_22_000002_create_form_integration.php');
        $migration->up();
        $after = DB::table('appointments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $references = [];

        foreach ($after as $index => $row) {
            $this->assertTrue(Str::isUuid($row['integration_id']));
            $references[] = $row['integration_id'];
            unset($row['integration_id'], $before[$index]['integration_id']);
            $this->assertSame($before[$index], $row);
        }
        $this->assertCount(2, array_unique($references));
    }

    public function test_a_signed_event_links_a_form_even_to_a_completed_appointment(): void
    {
        $appointment = Appointment::factory()->status(AppointmentStatus::Completed)->create();
        $data = $this->payload(['appointment_id' => $appointment->integration_id]);
        $this->send($data)->assertOk()->assertJsonPath('result', 'linked');
        $this->assertDatabaseHas('external_forms', ['external_id' => 'partner-form-1', 'appointment_id' => $appointment->id, 'status' => 'pending']);
        $form = ExternalForm::query()->firstOrFail();
        $this->assertSame($data['url'], $form->url);
        $this->assertNotSame($data['url'], DB::table('external_forms')->value('url'));
        $this->assertDatabaseCount('form_webhook_events', 1);
    }

    public function test_repeated_events_are_idempotent_but_event_ids_cannot_be_reused(): void
    {
        $data = $this->payload();
        $this->send($data)->assertAccepted();
        $this->send($data)->assertOk()->assertJsonPath('result', 'duplicate');
        $this->send([...$data, 'title' => 'Alterado'])->assertConflict();
        $this->assertDatabaseCount('external_forms', 1);
        $this->assertDatabaseCount('form_webhook_events', 1);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_unknown_references_wait_for_review_without_creating_appointments(): void
    {
        $reference = (string) Str::uuid();
        $this->send($this->payload(['appointment_id' => $reference]))->assertAccepted()->assertJsonPath('result', 'pending_review');
        $this->assertDatabaseHas('external_forms', ['appointment_id' => null, 'appointment_reference' => $reference]);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_newer_versions_replace_metadata_and_older_versions_do_not_overwrite_them(): void
    {
        $this->send($this->payload(['version' => 3, 'status' => 'completed']))->assertAccepted();
        $this->send($this->payload(['version' => 1, 'status' => 'pending']))->assertOk()->assertJsonPath('result', 'ignored_old_version');
        $this->send($this->payload(['version' => 4, 'status' => 'in_progress']))->assertAccepted();
        $this->assertDatabaseHas('external_forms', ['source_version' => 4, 'status' => 'in_progress']);
        $this->assertDatabaseCount('form_webhook_events', 3);
    }

    public function test_same_version_accepts_identical_data_and_rejects_different_data(): void
    {
        $data = $this->payload();
        $this->send($data)->assertAccepted();
        $this->send([...$data, 'event_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('result', 'ignored_old_version');
        $this->send([...$data, 'event_id' => (string) Str::uuid(), 'title' => 'Outra versão'])->assertConflict();
        $this->assertDatabaseCount('form_webhook_events', 2);
    }

    public function test_a_linked_form_cannot_be_moved_by_a_webhook(): void
    {
        $first = Appointment::factory()->create();
        $other = Appointment::factory()->create();
        $this->send($this->payload(['appointment_id' => $first->integration_id]))->assertOk();
        $this->send($this->payload(['appointment_id' => $other->integration_id, 'version' => 2]))->assertConflict();
        $this->send($this->payload(['version' => 2, 'status' => 'completed']))->assertOk();
        $this->assertDatabaseHas('external_forms', ['appointment_id' => $first->id, 'appointment_reference' => $first->integration_id, 'status' => 'completed']);
    }

    public function test_a_pending_form_can_be_resolved_by_a_newer_event(): void
    {
        $unknown = (string) Str::uuid();
        $this->send($this->payload(['appointment_id' => $unknown]))->assertAccepted();
        $appointment = Appointment::factory()->create();
        $this->send($this->payload(['appointment_id' => $appointment->integration_id, 'version' => 2]))->assertOk();
        $this->assertDatabaseHas('external_forms', ['appointment_id' => $appointment->id, 'appointment_reference' => $appointment->integration_id]);
    }

    public function test_deletions_keep_a_tombstone_and_hide_the_external_link(): void
    {
        $appointment = Appointment::factory()->status(AppointmentStatus::Completed)->create();
        $this->send($this->payload(['appointment_id' => $appointment->integration_id]))->assertOk();
        $this->send($this->payload(['version' => 2, 'status' => 'deleted', 'url' => null]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('appointments.show', $appointment))
            ->assertOk()->assertSee('Removido na plataforma')->assertDontSee('https://forms.example.test/forms/one', false);
        $this->send($this->payload())->assertOk()->assertJsonPath('result', 'ignored_old_version');
        $this->assertDatabaseHas('external_forms', ['status' => 'deleted', 'source_version' => 2]);
    }

    public function test_missing_invalid_expired_and_tampered_signatures_are_rejected(): void
    {
        $data = $this->payload();
        $this->postJson(self::PATH, $data)->assertUnauthorized();
        $this->signed('POST', self::PATH, $data, ['HTTP_X_FORMS_SIGNATURE' => 'sha256=invalid'])->assertUnauthorized();
        $this->signed('POST', self::PATH, $data, timestamp: (string) now()->subMinutes(6)->timestamp)->assertUnauthorized();
        $this->signed('POST', self::PATH, $data, timestamp: (string) now()->addMinutes(6)->timestamp)->assertUnauthorized();
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $badSignature = $this->signature('POST', self::PATH, $body.' ', $timestamp);
        $this->signed('POST', self::PATH, $data, ['HTTP_X_FORMS_SIGNATURE' => $badSignature])->assertUnauthorized();
        $this->assertDatabaseCount('external_forms', 0);
    }

    public function test_the_signature_covers_the_http_method_and_full_request_uri(): void
    {
        $data = $this->payload();
        $timestamp = (string) now()->timestamp;
        foreach (['GET', 'POST'] as $method) {
            $signature = $this->signature($method, self::PATH.'?unexpected=1', json_encode($data, JSON_THROW_ON_ERROR), $timestamp);
            $this->signed('POST', self::PATH, $data, ['HTTP_X_FORMS_SIGNATURE' => $signature])->assertUnauthorized();
        }
    }

    public function test_disabled_and_weakly_configured_integrations_fail_closed(): void
    {
        config(['services.forms.enabled' => false]);
        $this->send($this->payload())->assertStatus(503);
        config(['services.forms.enabled' => true, 'services.forms.webhook_secret' => 'too-short']);
        $this->send($this->payload())->assertStatus(503);
    }

    public function test_production_rejects_plain_http(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->send($this->payload())->assertStatus(400);
    }

    public function test_untrusted_urls_and_unknown_payload_fields_are_rejected(): void
    {
        foreach (['http://forms.example.test/a', 'https://evil.example/a', 'https://forms.example.test.evil.example/a', 'https://user:password@forms.example.test/a', 'https://forms.example.test:444/a', 'javascript:alert(1)', 'https://forms.example.test\\@evil.example/a'] as $url) {
            $this->send($this->payload(['url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->send($this->payload(['answers' => ['private' => 'data']]))->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->send($this->payload(['occurred_at' => 'tomorrow']))->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        $this->send($this->payload(['version' => 0]))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->send($this->payload(['appointment_id' => '123']))->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
        $this->assertDatabaseCount('external_forms', 0);
    }

    public function test_payloads_have_a_size_limit_and_require_json(): void
    {
        $this->send($this->payload(['title' => str_repeat('a', 66000)]))->assertStatus(413);
        $this->signed('POST', self::PATH, $this->payload(), ['CONTENT_TYPE' => 'text/plain'])->assertStatus(415);
    }

    public function test_signed_lookup_only_exposes_the_shared_reference_status_and_dates(): void
    {
        $appointment = Appointment::factory()->create(['notes' => 'Private notes']);
        $path = '/api/v1/integrations/forms/appointments/'.$appointment->integration_id;
        $this->signed('GET', $path)->assertOk()->assertExactJson([
            'appointment_id' => $appointment->integration_id,
            'status' => 'scheduled',
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($path)->assertUnauthorized();
        $appointment->delete();
        $this->signed('GET', $path)->assertNotFound();
    }

    public function test_staff_can_review_and_link_a_pending_form(): void
    {
        $this->send($this->payload())->assertAccepted();
        $appointment = Appointment::factory()->status(AppointmentStatus::Completed)->create();
        $form = ExternalForm::query()->firstOrFail();
        $this->actingAs(User::factory()->create());
        $this->get(route('forms.review', ['atendimento' => $appointment->integration_id]))->assertOk()->assertSee('Ficha de atendimento')->assertSee('Vincular a este atendimento');
        $this->post(route('forms.link', $form), ['appointment_id' => $appointment->integration_id])
            ->assertRedirect(route('appointments.show', $appointment))->assertSessionHas('notice', 'Formulário vinculado ao atendimento.');
        $this->assertDatabaseHas('external_forms', ['appointment_id' => $appointment->id, 'appointment_reference' => $appointment->integration_id]);
        $this->get(route('appointments.show', $appointment))->assertOk()->assertSee('Abrir formulário')
            ->assertSee($appointment->integration_id)->assertSee('https://forms.example.test/forms/one', false)
            ->assertSee('appointment_id='.$appointment->integration_id, false);
        $other = Appointment::factory()->create();
        $this->post(route('forms.link', $form), ['appointment_id' => $other->integration_id])->assertConflict();
    }

    public function test_viewers_and_inactive_users_cannot_review_or_link_forms(): void
    {
        $this->send($this->payload())->assertAccepted();
        $form = ExternalForm::query()->firstOrFail();
        $appointment = Appointment::factory()->create();
        $this->actingAs(User::factory()->viewer()->create());
        $this->get(route('forms.review'))->assertForbidden();
        $this->post(route('forms.link', $form), ['appointment_id' => $appointment->integration_id])->assertForbidden();
        $this->actingAs(User::factory()->inactive()->create())->get(route('forms.review'))->assertRedirect(route('login'));
        $this->assertDatabaseHas('external_forms', ['appointment_id' => null]);
    }

    public function test_domain_configuration_is_rechecked_when_rendering_links(): void
    {
        $appointment = Appointment::factory()->create();
        $this->send($this->payload(['appointment_id' => $appointment->integration_id]))->assertOk();
        config(['services.forms.allowed_hosts' => ['other.example.test']]);
        $this->actingAs(User::factory()->create())->get(route('appointments.show', $appointment))->assertOk()
            ->assertDontSee('https://forms.example.test/', false)->assertSee('Link indisponível');
    }

    public function test_fields_sent_as_lists_are_a_validation_error(): void
    {
        $this->send($this->payload(['url' => ['https://forms.example.test/1'], 'title' => ['Ficha']]))->assertUnprocessable();

        $this->assertDatabaseCount('external_forms', 0);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'event_id' => (string) Str::uuid(),
            'form_id' => 'partner-form-1',
            'appointment_id' => null,
            'version' => 1,
            'status' => 'pending',
            'title' => 'Ficha de atendimento',
            'url' => 'https://forms.example.test/forms/one',
            'occurred_at' => '2026-10-22T15:00:00Z',
        ], $overrides);
    }

    private function send(array $data): TestResponse
    {
        return $this->signed('POST', self::PATH, $data);
    }

    private function signature(string $method, string $path, string $body, string $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp."\n".$method."\n".$path."\n".$body, config('services.forms.webhook_secret'));
    }

    private function signed(string $method, string $path, ?array $data = null, array $headers = [], ?string $timestamp = null): TestResponse
    {
        $timestamp ??= (string) now()->timestamp;
        $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);

        return $this->call($method, $path, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FORMS_TIMESTAMP' => $timestamp,
            'HTTP_X_FORMS_SIGNATURE' => $this->signature($method, $path, $body, $timestamp),
        ], $headers), $body);
    }
}
