<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_the_link_is_sent_whatever_the_case_of_the_e_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'maria@feevale.br']);

        $this->post('/forgot-password', ['email' => ' Maria@Feevale.BR ']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_e_mail_is_in_portuguese(): void
    {
        $user = User::factory()->create();

        $mail = (new ResetPassword('token'))->toMail($user);

        $this->assertSame('Redefinir sua senha', $mail->subject);
        $this->assertSame('Redefinir senha', $mail->actionText);
    }

    public function test_repeated_requests_from_one_address_are_slowed_down(): void
    {
        Notification::fake();

        foreach (range(1, 6) as $attempt) {
            $this->post('/forgot-password', ['email' => "pessoa{$attempt}@feevale.br"]);
        }

        $this->post('/forgot-password', ['email' => 'outra@feevale.br'])->assertTooManyRequests();
    }

    public function test_a_reset_password_signs_out_every_device(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $user->createToken('celular');
        DB::table('sessions')->insert(['id' => 'sessao-antiga', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-antiga']);
    }
}
