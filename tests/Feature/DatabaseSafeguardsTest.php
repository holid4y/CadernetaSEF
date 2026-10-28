<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\ActivityType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_appointment_history_can_not_be_rewritten(): void
    {
        $appointment = Appointment::factory()->create();
        $activity = $appointment->recordActivity(ActivityType::Created, null);

        $this->assertRejected(fn () => DB::table('appointment_activities')->where('id', $activity->id)->update(['event_type' => 'cancelled']));
        $this->assertRejected(fn () => DB::table('appointment_activities')->where('id', $activity->id)->delete());
    }

    public function test_appointments_are_never_removed_from_the_database(): void
    {
        $appointment = Appointment::factory()->create();

        $appointment->delete();
        $this->assertSoftDeleted($appointment);

        $this->assertRejected(fn () => $appointment->forceDelete());
    }

    public function test_changes_to_personal_data_are_audited_without_copying_values(): void
    {
        $client = Client::factory()->create(['document' => '529.982.247-25']);
        DB::statement("SELECT set_config('app.user_id', '42', false)");

        $client->update(['email' => 'novo@example.com', 'document' => '111.444.777-35']);

        $audit = DB::table('data_audits')->where('table_name', 'clients')->where('operation', 'UPDATE')->sole();

        $this->assertSame(['document', 'document_index', 'email'], json_decode($audit->changed_columns));
        $this->assertSame(42, $audit->app_user_id);
        $this->assertSame(DB::selectOne('SELECT session_user AS name')->name, $audit->db_user);
        $this->assertStringNotContainsString('novo@example.com', json_encode($audit));
    }

    public function test_the_audit_trail_itself_is_append_only(): void
    {
        Client::factory()->create();

        $this->assertRejected(fn () => DB::table('data_audits')->delete());
        $this->assertRejected(fn () => DB::statement('TRUNCATE data_audits'));
    }

    public function test_each_request_tells_the_database_who_is_acting(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame((string) $user->id, DB::selectOne("SELECT current_setting('app.user_id', true) AS id")->id);
    }

    public function test_two_active_clients_can_not_share_a_document(): void
    {
        Client::factory()->create(['document' => '529.982.247-25']);

        $this->expectException(QueryException::class);

        Client::factory()->create(['document' => '52998224725']);
    }

    public function test_consent_to_reminders_is_dated(): void
    {
        $this->freezeSecond();
        $client = Client::factory()->create(['accepts_reminders' => true]);

        $this->assertTrue($client->reminders_consented_at->equalTo(now()));

        $client->update(['accepts_reminders' => false]);
        $this->assertNull($client->fresh()->reminders_consented_at);
    }

    public function test_the_report_view_carries_no_personal_data(): void
    {
        Appointment::factory()->create();

        $columns = array_keys((array) DB::table('appointment_facts')->first());

        $this->assertSame([], array_intersect($columns, ['name', 'email', 'phone', 'document', 'notes', 'trade_name']));
    }

    private function assertRejected(callable $statement): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('The database accepted a change it should refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('is not allowed', $exception->getMessage());
        }
    }
}
