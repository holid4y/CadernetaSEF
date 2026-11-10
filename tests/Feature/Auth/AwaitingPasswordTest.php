<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Users\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AwaitingPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function awaiting(array $attributes = []): User
    {
        return User::factory()->create(['email' => 'maria@feevale.test', 'password' => null, ...$attributes]);
    }

    private function ask(string $email)
    {
        return $this->postJson('/api/v1/account-status', ['email' => $email]);
    }

    public function test_an_account_without_a_password_is_announced_whatever_the_case(): void
    {
        $this->awaiting();

        $this->ask('maria@feevale.test')->assertOk()->assertExactJson(['awaiting_password' => true]);
        $this->ask('Maria@Feevale.TEST')->assertOk()->assertExactJson(['awaiting_password' => true]);
    }

    public function test_an_account_with_a_password_looks_like_an_address_that_does_not_exist(): void
    {
        User::factory()->create(['email' => 'joana@feevale.test']);

        $withPassword = $this->ask('joana@feevale.test')->assertOk()->json();
        $unknown = $this->ask('ninguem@feevale.test')->assertOk()->json();

        $this->assertSame(['awaiting_password' => false], $withPassword);
        $this->assertSame($withPassword, $unknown);
    }

    public function test_a_blocked_account_is_not_announced(): void
    {
        $this->awaiting(['active' => false]);

        $this->ask('maria@feevale.test')->assertOk()->assertExactJson(['awaiting_password' => false]);
    }

    public function test_the_question_needs_an_e_mail_and_has_a_limit(): void
    {
        $this->ask('nao-e-email')->assertUnprocessable();
        $this->postJson('/api/v1/account-status', ['email' => ['a@b.test']])->assertUnprocessable();

        foreach (range(1, 30) as $attempt) {
            $this->ask('maria@feevale.test');
        }

        $this->ask('maria@feevale.test')->assertTooManyRequests();
    }

    public function test_an_account_without_a_password_can_not_sign_in(): void
    {
        $this->awaiting();

        $this->post('/login', ['email' => 'maria@feevale.test', 'password' => 'qualquer-coisa-1'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->postJson('/api/v1/login', ['email' => 'maria@feevale.test', 'password' => 'qualquer-coisa-1', 'device_name' => 'app'])
            ->assertUnprocessable();
    }

    public function test_the_person_creates_the_password_through_the_link_and_the_notice_goes_away(): void
    {
        Notification::fake();
        $user = $this->awaiting();

        $this->post('/forgot-password', ['email' => 'maria@feevale.test']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification): bool {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => 'maria@feevale.test',
                'password' => 'minha-senha-2026',
                'password_confirmation' => 'minha-senha-2026',
            ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('minha-senha-2026', $user->fresh()->password));
        $this->ask('maria@feevale.test')->assertExactJson(['awaiting_password' => false]);

        $this->post('/login', ['email' => 'maria@feevale.test', 'password' => 'minha-senha-2026']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_login_page_carries_the_notice_and_the_form_receives_the_address(): void
    {
        $this->get('/login')->assertOk()->assertSee('account-status', false)->assertSee(__('auth.create_password'));

        $this->get('/forgot-password?email=maria@feevale.test')->assertOk()->assertSee('value="maria@feevale.test"', false);
        $this->get('/forgot-password?email[]=maria@feevale.test')->assertOk()->assertDontSee('maria@feevale.test');
    }

    public function test_the_reset_link_ignores_the_host_of_the_request(): void
    {
        Notification::fake();
        config(['app.url' => 'https://cadernetasef.example']);
        $user = $this->awaiting();

        $this->withServerVariables(['HTTP_HOST' => 'atacante.example'])
            ->postJson('/api/v1/forgot-password', ['email' => 'maria@feevale.test'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith("https://cadernetasef.example/reset-password/{$notification->token}", $url);

            return true;
        });
    }

    public function test_an_account_can_be_created_without_a_password(): void
    {
        $this->artisan('users:create', ['email' => 'Nova@Feevale.test', 'name' => 'Nova', '--role' => 'admin', '--without-password' => true])
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('nova@feevale.test', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNull($user->password);
        $this->ask('nova@feevale.test')->assertExactJson(['awaiting_password' => true]);
    }

    public function test_a_password_can_be_removed_on_the_server_and_sessions_end(): void
    {
        $user = User::factory()->create(['email' => 'maria@feevale.test']);
        $user->createToken('celular');

        $this->artisan('users:password', ['email' => 'maria@feevale.test', '--clear' => true])->assertSuccessful();

        $this->assertNull($user->fresh()->password);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_migration_turns_empty_passwords_into_no_password(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => '']);

        (require database_path('migrations/2026_11_10_000001_allow_accounts_without_password.php'))->up();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
    }
}
