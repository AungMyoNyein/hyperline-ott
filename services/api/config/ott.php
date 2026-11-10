<?php

return [

    'playback' => [
        // Session expiry alone cannot revoke a CDN URL. The signing provider must
        // enforce this deadline for manifests, child playlists, segments and keys.
        'ttl_seconds' => (int) env('OTT_PLAYBACK_TTL_SECONDS', 900),

        // Production always requires signing. Unsigned development streams require
        // an explicit false setting outside production.
        'signed_urls' => env('OTT_SIGNED_URLS', true),
        'signer' => env('OTT_PLAYBACK_SIGNER'),

        'require_authentication' => env('OTT_PLAYBACK_REQUIRES_AUTH', true),

        // Phase 1 has no billing: accounts with no subscription_expires_at recorded
        // are treated as entitled. Disable once plans are enforced.
        'allow_missing_subscription' => env('OTT_ALLOW_MISSING_SUBSCRIPTION', true),
    ],

    'external_cdn' => [
        // Current architecture: existing CDN only; no infrastructure changes.
        'verified' => env('OTT_EXISTING_CDN_VERIFIED', false),
        'authorizer' => env('OTT_EXISTING_CDN_AUTHORIZER'),
    ],

    'cdn' => [
        // Legacy same-site cookie experiment only; NOT the external CDN deployment path.
        'verified' => env('OTT_NGINX_CDN_VERIFIED', false),
        'base_url' => env('OTT_NGINX_CDN_URL', ''),
        'cookie_domain' => env('OTT_NGINX_COOKIE_DOMAIN', ''),
        'secret' => env('OTT_NGINX_SIGNING_SECRET'),
        'assets_file' => env('OTT_NGINX_ASSETS_FILE'),
        'cookie_ttl_seconds' => (int) env('OTT_NGINX_COOKIE_TTL_SECONDS', 300),
        'max_session_seconds' => (int) env('OTT_PLAYBACK_MAX_SESSION_SECONDS', 14400),
    ],

    'streams' => [
        'allowed_schemes' => ['https'],

        'allowed_ports' => [443],

        // Stored stream hosts are resolved during validation so a public hostname
        // cannot be used to smuggle an internal address into server-side fetches.
        'reject_unresolved_hosts' => env('OTT_REJECT_UNRESOLVED_STREAM_HOSTS', true),
    ],

    'catalog' => [
        'page_size' => (int) env('OTT_CATALOG_PAGE_SIZE', 24),
        'max_page_size' => 100,
    ],

    'admin' => [
        // Bootstrap password for `php artisan hyperline:admin`. Read through config
        // so the command still works when the app runs with cached configuration.
        'initial_password' => env('HYPERLINE_INITIAL_ADMIN_PASSWORD'),
    ],

];
