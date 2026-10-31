<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Enums\UserRole;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_administrator_is_created_on_the_server(): void
    {
        $this->artisan('users:create', ['email' => 'Coordenacao@Feevale.test', 'name' => 'Coordenação', '--role' => 'admin'])
            ->expectsQuestion('Password (at least 10 characters, letters and numbers)', 'senha-longa-2026')
            ->expectsQuestion('Password again', 'senha-longa-2026')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('coordenacao@feevale.test', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('senha-longa-2026', $user->password));
    }

    public function test_weak_or_mistyped_passwords_are_refused(): void
    {
        $this->artisan('users:create', ['email' => 'a@feevale.test', 'name' => 'A'])
            ->expectsQuestion('Password (at least 10 characters, letters and numbers)', 'curta')
            ->expectsQuestion('Password again', 'curta')
            ->assertFailed();

        $this->artisan('users:create', ['email' => 'a@feevale.test', 'name' => 'A'])
            ->expectsQuestion('Password (at least 10 characters, letters and numbers)', 'senha-longa-2026')
            ->expectsQuestion('Password again', 'senha-longa-2027')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_blocking_an_account_signs_it_out_everywhere(): void
    {
        $user = User::factory()->create(['email' => 'maria@feevale.test']);
        $user->createToken('celular');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('users:deactivate', ['email' => 'maria@feevale.test'])->assertSuccessful();

        $this->assertFalse($user->fresh()->active);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        $this->artisan('users:deactivate', ['email' => 'maria@feevale.test', '--undo' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->active);
    }

    public function test_blocking_an_account_also_disconnects_its_google_calendar(): void
    {
        $user = User::factory()->create(['email' => 'ana@agenda.local']);
        GoogleAccount::query()->create([
            'user_id' => $user->id, 'email' => 'ana.google@gmail.com', 'refresh_token' => 'refresh-token',
            'access_token' => 'access-token', 'access_token_expires_at' => now()->addHour(),
            'scopes' => 'openid', 'calendar_id' => 'ana-sef',
        ]);
        Http::fake(['*' => Http::response(status: 204)]);

        $this->artisan('users:deactivate', ['email' => 'ana@agenda.local'])->assertSuccessful();

        $this->assertDatabaseEmpty('google_accounts');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke');
    }

    public function test_a_password_is_reset_on_the_server_and_old_sessions_end(): void
    {
        $user = User::factory()->create(['email' => 'maria@feevale.test']);
        $user->createToken('celular');

        $this->artisan('users:password', ['email' => 'maria@feevale.test'])
            ->expectsQuestion('New password (at least 10 characters, letters and numbers)', 'nova-senha-2026')
            ->expectsQuestion('New password again', 'nova-senha-2026')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('nova-senha-2026', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
