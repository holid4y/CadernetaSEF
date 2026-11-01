<?php

declare(strict_types=1);

namespace App\Domain\Google;

use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class GoogleAuthorization
{
    // A calendar of its own for the copies, the list of calendars to choose
    // from and reading the one chosen. The person can uncheck any of them.
    public const CALENDAR_SCOPES = [
        'https://www.googleapis.com/auth/calendar.app.created',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'https://www.googleapis.com/auth/calendar.events.readonly',
    ];

    public const SCOPES = ['openid', 'email', ...self::CALENDAR_SCOPES];

    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function url(string $state, string $verifier): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => route('google.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{email: string, refresh_token: string, access_token: string, expires_in: int, scopes: string}
     */
    public function exchange(string $code, string $verifier): array
    {
        $response = $this->post(self::TOKEN_URL, [
            'code' => $code,
            'code_verifier' => $verifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => route('google.callback'),
        ]);

        // The ID token came straight from Google over TLS, so its claims can be
        // read as they are. Google names the e-mail scope in its long form, so
        // only the calendar ones are compared.
        $claims = json_decode(base64_decode(strtr(explode('.', (string) $response->json('id_token'))[1] ?? '', '-_', '+/')), true);
        $missing = array_diff(self::CALENDAR_SCOPES, explode(' ', (string) $response->json('scope')));
        $email = is_array($claims) && ($claims['email_verified'] ?? false) === true ? ($claims['email'] ?? null) : null;

        if ($missing !== [] || ! is_string($email) || ! filled($response->json('refresh_token'))) {
            $this->revoke((string) ($response->json('refresh_token') ?? $response->json('access_token')));

            throw ValidationException::withMessages(['google' => __('google.errors.permissions')]);
        }

        return [
            'email' => mb_strtolower($email),
            'refresh_token' => $response->json('refresh_token'),
            'access_token' => $response->json('access_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
            'scopes' => (string) $response->json('scope'),
        ];
    }

    public function accessToken(GoogleAccount $account): string
    {
        if ($account->access_token !== null && $account->access_token_expires_at?->isAfter(now()->addMinute())) {
            return $account->access_token;
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->json('error') === 'invalid_grant') {
            $account->update(['failed_at' => now(), 'access_token' => null, 'access_token_expires_at' => null]);

            throw new GoogleAccessRevoked;
        }

        $response->throw();
        $account->update([
            'access_token' => $response->json('access_token'),
            'access_token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $account->access_token;
    }

    // Revoking is a courtesy to the person: the copy of the token here is
    // deleted either way, so a failure is not worth stopping for.
    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function post(string $url, array $data): Response
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post($url, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                ...$data,
            ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['google' => __('google.errors.unreachable')]);
        }

        if ($response->failed()) {
            throw ValidationException::withMessages(['google' => __('google.errors.refused')]);
        }

        return $response;
    }
}
