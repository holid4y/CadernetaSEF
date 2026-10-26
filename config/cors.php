<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only the mobile API is called from another origin: the Capacitor apps
    | (https://localhost on Android, capacitor://localhost on iOS) and the
    | PWA when it is served from a different host. Authentication travels in
    | the Authorization header, never in cookies.
    |
    */

    'paths' => ['api/v1/login', 'api/v1/logout', 'api/v1/forgot-password', 'api/v1/mobile/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://localhost,capacitor://localhost')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
