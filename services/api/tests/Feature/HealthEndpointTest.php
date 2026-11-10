<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_protected_api_without_accept_header_returns_json_unauthorized(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_health_reports_ok_without_credentials(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'hyperline-api'])
            ->assertJsonStructure(['status', 'service', 'app', 'time'])
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_health_does_not_leak_dependency_details(): void
    {
        $content = $this->getJson('/api/v1/health')->getContent();

        foreach (['password', 'postgres', 'redis', 'dsn', 'sqlstate'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $content);
        }
    }
}
