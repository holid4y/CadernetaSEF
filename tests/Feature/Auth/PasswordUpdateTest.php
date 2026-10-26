<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('nova-senha-2026', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    public function test_a_new_password_signs_out_the_phone_and_other_browsers(): void
    {
        $user = User::factory()->create();
        $user->createToken('celular');
        DB::table('sessions')->insert(['id' => 'outro-navegador', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)
            ->from('/perfil')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'outro-navegador']);
        $this->assertAuthenticatedAs($user);
    }
}
