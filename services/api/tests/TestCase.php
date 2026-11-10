<?php

namespace Tests;

use App\Support\Contracts\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\ArrayHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected ArrayHostResolver $hosts;

    protected function setUp(): void
    {
        parent::setUp();
        // Existing entitlement tests exercise explicitly unsigned test streams.
        config(['ott.playback.signed_urls' => false]);

        // Stream validation resolves stored hosts against DNS. Tests pin the
        // answers so the suite never depends on the network.
        $this->hosts = new ArrayHostResolver([
            'cdn.example.test' => ['93.184.216.34'],
            'other-cdn.example.test' => ['198.51.100.9'],
            'private.example.test' => ['10.0.0.5'],
            'loopback.example.test' => ['127.0.0.1'],
            'mixed.example.test' => ['93.184.216.34', '172.16.9.9'],
        ]);

        $this->app->instance(HostResolver::class, $this->hosts);
    }
}
