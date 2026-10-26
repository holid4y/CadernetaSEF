<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains and Guards
    |--------------------------------------------------------------------------
    |
    | The web pages use the session guard and the mobile app sends bearer
    | tokens. No domain receives Sanctum's cookie authentication, and the
    | API ignores the session guard, so a browser cookie never authorizes
    | a call to /api.
    |
    */

    'stateful' => [],

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | The app keeps the token in memory only, so one work shift is enough.
    | Expired tokens are removed by the scheduled sanctum:prune-expired.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION', 720),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
