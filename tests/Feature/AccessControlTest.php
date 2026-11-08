<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_private_areas(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/perfil')->assertRedirect(route('login'));
    }

    public function test_guests_see_what_the_system_is_and_where_its_privacy_policy_is(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Caderneta SEF')
            ->assertSee(route('login'))
            ->assertSee(route('privacy'))
            ->assertSee('Google Agenda');

        $this->get('/login')->assertSee(route('privacy'));
    }

    public function test_root_redirects_authenticated_users_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_self_registration_is_disabled(): void
    {
        $this->assertFalse(
            app('router')->has('register'),
            'Accounts must be created by administrators, not through public registration.',
        );

        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_users_can_not_delete_their_own_account(): void
    {
        $this->assertFalse(app('router')->has('profile.destroy'));

        $this->actingAs(User::factory()->create())
            ->delete('/perfil')
            ->assertStatus(405);
    }
}
