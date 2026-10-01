<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    public function test_landing_liveness_and_docs(): void
    {
        $this->getJson('/')->assertOk()->assertJsonPath('data.name', 'Sentinel API');
        $this->getJson('/health')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->get('/docs')->assertOk()->assertSee('SwaggerUIBundle')->assertSee('/openapi.json');
        $this->get('/horizon')->assertForbidden();
    }

    public function test_readiness_reports_dependency_failure_without_details(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('redis-secret-host'));
        $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('data.checks.redis', 'unavailable')->assertDontSee('redis-secret-host');
    }

    public function test_openapi_covers_every_v1_route_and_declares_bearer_auth(): void
    {
        $spec = json_decode(file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->assertArrayHasKey(strtolower($method), $spec['paths']['/'.$route->uri()], $route->uri());
            }
        }
    }
}
