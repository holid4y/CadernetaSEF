<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Domain\Google\GoogleAuthorization;
use App\Jobs\PullGoogleEvents;
use App\Jobs\SyncGoogleAccount;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client-id.apps.googleusercontent.com', 'services.google.client_secret' => 'secret']);
        Queue::fake();
    }

    public function test_a_member_is_sent_to_google_with_offline_access_and_pkce(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('google.connect'));

        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $pending = session('google.authorization');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $response->headers->get('Location'));
        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame(route('google.callback'), $query['redirect_uri']);
        $this->assertSame(implode(' ', GoogleAuthorization::SCOPES), $query['scope']);
        $this->assertSame(['offline', 'consent', 'S256'], [$query['access_type'], $query['prompt'], $query['code_challenge_method']]);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
    }

    public function test_the_privacy_policy_google_asks_for_is_public(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Política de privacidade')->assertSee('uso limitado');
    }

    public function test_viewers_can_not_connect(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('google.connect'))->assertForbidden();
        $this->get(route('google.show'))->assertForbidden();
    }

    public function test_nothing_is_offered_until_the_server_has_a_google_client(): void
    {
        config(['services.google.client_id' => null]);
        $this->actingAs(User::factory()->create());

        $this->get(route('google.show'))->assertOk()->assertSee(__('google.errors.not_configured'))->assertDontSee(__('google.continue'));
        $this->get(route('google.connect'))->assertNotFound();
    }

    public function test_the_answer_from_google_connects_the_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('google.connect'));
        $pending = session('google.authorization');
        $this->fakeTokens('ana.google@gmail.com');

        $this->get(route('google.callback', ['state' => $pending['state'], 'code' => 'the-code']))
            ->assertRedirect(route('google.show'))
            ->assertSessionHas('notice', __('google.flash.connected', ['email' => 'ana.google@gmail.com']));

        $account = GoogleAccount::query()->sole();
        $this->assertSame([$user->id, 'ana.google@gmail.com', 'refresh-token'], [$account->user_id, $account->email, $account->refresh_token]);
        $this->assertNotSame('refresh-token', DB::table('google_accounts')->value('refresh_token'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'the-code'
            && $request['code_verifier'] === $pending['verifier']
            && $request['grant_type'] === 'authorization_code');
        Queue::assertPushed(SyncGoogleAccount::class, fn (SyncGoogleAccount $job): bool => $job->accountId === $account->id);
    }

    public function test_an_answer_with_another_state_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        Http::fake();

        $this->get(route('google.callback', ['state' => str_repeat('x', 40), 'code' => 'the-code']))
            ->assertRedirect(route('google.show'))
            ->assertSessionHasErrors(['google' => __('google.errors.expired')]);

        Http::assertNothingSent();
        $this->assertDatabaseEmpty('google_accounts');
    }

    public function test_the_state_works_only_once(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        $state = session('google.authorization')['state'];
        $this->fakeTokens('ana.google@gmail.com');

        $this->get(route('google.callback', ['state' => $state, 'code' => 'the-code']));
        $this->get(route('google.callback', ['state' => $state, 'code' => 'the-code']))
            ->assertSessionHasErrors(['google' => __('google.errors.expired')]);

        Http::assertSentCount(1);
    }

    public function test_an_old_request_expires(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        $state = session('google.authorization')['state'];
        Http::fake();
        $this->travel(11)->minutes();

        $this->get(route('google.callback', ['state' => $state, 'code' => 'the-code']))
            ->assertSessionHasErrors(['google' => __('google.errors.expired')]);

        Http::assertNothingSent();
    }

    public function test_refusing_on_google_connects_nothing(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        Http::fake();

        $this->get(route('google.callback', ['state' => session('google.authorization')['state'], 'error' => 'access_denied']))
            ->assertSessionHasErrors(['google' => __('google.errors.denied')]);

        $this->assertDatabaseEmpty('google_accounts');
    }

    public function test_a_permission_left_unchecked_is_refused_and_the_grant_revoked(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        $this->fakeTokens('ana.google@gmail.com', scopes: 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.app.created');

        $this->get(route('google.callback', ['state' => session('google.authorization')['state'], 'code' => 'the-code']))
            ->assertSessionHasErrors(['google' => __('google.errors.permissions')]);

        $this->assertDatabaseEmpty('google_accounts');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'refresh-token');
    }

    public function test_someone_else_can_not_finish_a_connection_started_in_the_web(): void
    {
        $this->actingAs(User::factory()->create())->get(route('google.connect'));
        $state = session('google.authorization')['state'];
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->get(route('google.callback', ['state' => $state, 'code' => 'the-code']))
            ->assertSessionHasErrors(['google' => __('google.errors.expired')]);

        $this->assertDatabaseEmpty('google_accounts');
    }

    public function test_the_app_link_opens_google_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $url = $this->postJson(route('api.google.connect'))->assertOk()->json('url');
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertRedirect();
        $this->assertSame(['user' => $user->id, 'via' => 'app'], array_intersect_key(session('google.authorization'), ['user' => 0, 'via' => 0]));

        $this->get($url)->assertOk()->assertSee(__('google.errors.link'));
    }

    public function test_the_app_link_expires(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $url = $this->postJson(route('api.google.connect'))->json('url');
        $this->app['auth']->forgetGuards();
        $this->travel(6)->minutes();

        $this->get($url)->assertOk()->assertSee(__('google.errors.link'));
    }

    public function test_the_app_connection_ends_on_a_page_that_signs_nobody_in(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $url = $this->postJson(route('api.google.connect'))->json('url');
        $this->app['auth']->forgetGuards();
        $this->get($url);
        $this->fakeTokens('ana.google@gmail.com');

        $this->get(route('google.callback', ['state' => session('google.authorization')['state'], 'code' => 'the-code']))
            ->assertOk()
            ->assertSee('ana.google@gmail.com')
            ->assertSee($user->name);

        $this->assertGuest('web');
        $this->assertSame($user->id, GoogleAccount::query()->sole()->user_id);
    }

    public function test_viewers_get_no_app_link(): void
    {
        Sanctum::actingAs(User::factory()->viewer()->create(), ['mobile']);

        $this->postJson(route('api.google.connect'))->assertForbidden();
        $this->getJson(route('api.google.show'))->assertOk()->assertJsonPath('google.available', false);
    }

    public function test_disconnecting_removes_the_calendar_and_revokes_the_access(): void
    {
        $user = User::factory()->create();
        $this->connected($user, ['calendar_id' => 'sef@group.calendar.google.com']);
        Http::fake(['*' => Http::response(status: 204)]);

        $this->actingAs($user)->delete(route('google.destroy'))->assertRedirect(route('google.show'));

        $this->assertDatabaseEmpty('google_accounts');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/sef%40group.calendar.google.com');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke');
    }

    public function test_choosing_the_calendar_to_bring_in(): void
    {
        $user = User::factory()->create();
        $account = $this->connected($user);
        Http::fake(['https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
            ['id' => 'ana@gmail.com', 'summary' => 'Ana', 'primary' => true],
            ['id' => 'sef@group.calendar.google.com', 'summary' => 'Atendimentos SEF'],
        ]])]);

        $this->actingAs($user)->patch(route('google.update'), ['calendar' => 'sef@group.calendar.google.com'])
            ->assertSessionHas('notice', __('google.flash.source_saved', ['name' => 'Atendimentos SEF']));
        $this->patch(route('google.update'), ['calendar' => 'someone-else@gmail.com'])
            ->assertSessionHasErrors(['calendar' => __('google.errors.calendar')]);

        $this->assertSame(['sef@group.calendar.google.com', 'Atendimentos SEF'], [$account->fresh()->source_calendar_id, $account->fresh()->source_calendar_name]);
        Queue::assertPushed(PullGoogleEvents::class, 1);
    }

    /**
     * @param  list<string>  $scopes
     */
    // Google answers with the long form of "email" and in its own order.
    private const GRANTED = 'https://www.googleapis.com/auth/calendar.events.readonly https://www.googleapis.com/auth/calendar.calendarlist.readonly '
        .'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.app.created openid';

    private function fakeTokens(string $email, ?string $scopes = self::GRANTED): void
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['email' => $email, 'email_verified' => true])), '+/', '-_'), '=');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 3599,
                'scope' => $scopes,
                'id_token' => "header.{$claims}.signature",
            ]),
            'https://oauth2.googleapis.com/revoke' => Http::response(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connected(User $user, array $attributes = []): GoogleAccount
    {
        return GoogleAccount::query()->create([
            'user_id' => $user->id,
            'email' => 'ana.google@gmail.com',
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => implode(' ', GoogleAuthorization::SCOPES),
            ...$attributes,
        ]);
    }
}
