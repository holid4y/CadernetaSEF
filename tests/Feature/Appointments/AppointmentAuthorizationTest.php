<?php

declare(strict_types=1);

namespace Tests\Feature\Appointments;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_active_role_can_see_the_agenda(): void
    {
        $appointment = Appointment::factory()->create();

        foreach ([User::factory()->admin()->create(), User::factory()->create(), User::factory()->viewer()->create()] as $user) {
            $this->assertTrue($user->can('view', $appointment), "{$user->role->value} could not view.");
        }
    }

    public function test_viewers_can_not_create_edit_or_cancel(): void
    {
        $viewer = User::factory()->viewer()->create();
        $appointment = Appointment::factory()->create();

        $this->assertFalse($viewer->can('create', Appointment::class));
        $this->assertFalse($viewer->can('update', $appointment));
        $this->assertFalse($viewer->can('cancel', $appointment));
    }

    public function test_members_manage_appointments_but_only_admins_delete(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->create();

        $this->assertTrue($member->can('update', $appointment));
        $this->assertTrue($member->can('cancel', $appointment));
        $this->assertFalse($member->can('delete', $appointment));
        $this->assertTrue($admin->can('delete', $appointment));
        $this->assertFalse($admin->can('forceDelete', $appointment));
    }

    public function test_deactivated_accounts_lose_every_permission(): void
    {
        $user = User::factory()->admin()->inactive()->create();
        $appointment = Appointment::factory()->create();

        $this->assertFalse($user->can('view', $appointment));
        $this->assertFalse($user->can('create', Appointment::class));
        $this->assertFalse($user->can('delete', $appointment));
    }

    public function test_clients_with_history_can_not_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $withHistory = Appointment::factory()->create()->client;

        $this->assertFalse($admin->can('delete', $withHistory));
        $this->assertTrue($admin->can('delete', Client::factory()->create()));
    }

    public function test_only_admins_manage_the_service_catalog(): void
    {
        $service = Service::factory()->create();

        $this->assertTrue(User::factory()->admin()->create()->can('update', $service));
        $this->assertFalse(User::factory()->create()->can('update', $service));
        $this->assertTrue(User::factory()->admin()->create()->can('delete', $service));
        $this->assertFalse(User::factory()->create()->can('delete', $service));
    }
}
