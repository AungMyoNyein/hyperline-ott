<?php

return [

    // Laravel API CORS only. The external video CDN has its own independent policy.
    // Login remains Sanctum bearer authentication; credentials do not enable cookie login.
    // Exact HTTP(S) origins only: never reflect arbitrary Origin or permit wildcards.
    'paths' => [
        'api/*',
        'login',
        'logout',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', env('CORS_ALLOWED_ORIGINS', 'https://ott.zentunnel.net,https://cms.zentunnel.net')),
    ), static fn (string $origin): bool => preg_match('~\Ahttps?://[a-z0-9.-]+(?::[0-9]+)?\z~iD', $origin) === 1))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-Token'],

    'exposed_headers' => ['RateLimit-Limit', 'RateLimit-Remaining', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', true), FILTER_VALIDATE_BOOLEAN),

];
