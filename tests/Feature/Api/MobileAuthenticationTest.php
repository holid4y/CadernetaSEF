<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_returns_a_token_and_the_profile(): void
    {
        $user = User::factory()->create(['email' => 'maria@agenda.test', 'name' => 'Maria']);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'maria@agenda.test',
            'password' => 'password',
            'device_name' => 'Android da Maria',
        ]);

        $response->assertOk()
            ->assertJsonPath('user', ['id' => $user->id, 'name' => 'Maria', 'email' => 'maria@agenda.test', 'role' => 'team', 'active' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('$2y$', $response->getContent());
        $this->assertSame('Android da Maria', $user->tokens()->sole()->name);

        $this->withToken($response->json('token'))->getJson('/api/v1/mobile/bootstrap')->assertOk();
    }

    public function test_a_wrong_password_is_refused_in_portuguese(): void
    {
        User::factory()->create(['email' => 'maria@agenda.test']);

        $this->postJson('/api/v1/login', ['email' => 'maria@agenda.test', 'password' => 'errada', 'device_name' => 'app'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'As credenciais indicadas não coincidem com as registradas no sistema.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_accounts_can_not_sign_in(): void
    {
        User::factory()->inactive()->create(['email' => 'maria@agenda.test']);

        $this->postJson('/api/v1/login', ['email' => 'maria@agenda.test', 'password' => 'password', 'device_name' => 'app'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esta conta está desativada. Entre em contato com um administrador.');
    }

    public function test_the_lockout_is_shared_with_the_login_page(): void
    {
        User::factory()->create(['email' => 'maria@agenda.test']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'maria@agenda.test', 'password' => 'errada']);
        }

        $this->postJson('/api/v1/login', ['email' => 'maria@agenda.test', 'password' => 'password', 'device_name' => 'app'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', fn (string $message): bool => str_contains($message, 'limite de tentativas'));
    }

    public function test_bursts_from_one_address_are_slowed_down(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->postJson('/api/v1/login', ['email' => "pessoa{$attempt}@agenda.test", 'password' => 'x', 'device_name' => 'app']);
        }

        $this->postJson('/api/v1/login', ['email' => 'outra@agenda.test', 'password' => 'x', 'device_name' => 'app'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Muitas tentativas seguidas. Aguarde um minuto e tente de novo.')
            ->assertHeader('Retry-After');
    }

    public function test_signing_out_revokes_only_that_device(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('celular')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withToken($phone)->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($phone)->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sua sessão expirou. Entre novamente.');
        $this->app['auth']->forgetGuards();

        $this->withToken($tablet)->getJson('/api/v1/mobile/bootstrap')->assertOk();
    }

    public function test_deactivating_an_account_ends_every_mobile_session(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('celular')->plainTextToken;
        $user->update(['active' => false]);

        $this->withToken($token)->getJson('/api/v1/mobile/bootstrap')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Esta conta está desativada. Entre em contato com um administrador.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_tokens_expire_after_a_work_shift(): void
    {
        $token = User::factory()->create()->createToken('celular')->plainTextToken;

        $this->travel(13)->hours();

        $this->withToken($token)->getJson('/api/v1/mobile/bootstrap')->assertUnauthorized();
    }

    public function test_a_browser_session_does_not_open_the_api(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/mobile/bootstrap')->assertUnauthorized();
    }

    public function test_password_recovery_answers_the_same_for_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'maria@agenda.test']);

        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'Maria@Agenda.test'])->assertOk();
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'ninguem@agenda.test'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_there_is_no_public_sign_up(): void
    {
        $this->postJson('/api/v1/register', ['email' => 'nova@agenda.test', 'password' => 'password'])->assertNotFound();
    }

    public function test_only_the_native_apps_may_call_from_another_origin(): void
    {
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/mobile/bootstrap', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);

        $preflight('capacitor://localhost')->assertHeader('Access-Control-Allow-Origin', 'capacitor://localhost');
        $preflight('https://localhost')->assertHeader('Access-Control-Allow-Origin', 'https://localhost');
        $preflight('https://site-qualquer.example')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_an_e_mail_sent_as_a_list_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/login', ['email' => ['maria@agenda.test'], 'password' => 'password', 'device_name' => 'app'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
