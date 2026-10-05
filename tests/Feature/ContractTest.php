<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContractTest extends TestCase
{
    public function test_openapi_covers_v2_routes_and_has_unique_operations_and_scopes(): void
    {
        $spec = json_decode(file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $ids = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (isset($operation['operationId'])) {
                    $this->assertNotContains($operation['operationId'], $ids);
                    $ids[] = $operation['operationId'];
                }
            }
        }
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v2/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $spec['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertNotNull($operation, $route->uri().' '.$method);
                $this->assertNotEmpty($operation['summary']);
                foreach ($route->middleware() as $middleware) {
                    if (preg_match('/^organization:((monitors|incidents|maintenance|analytics|status-pages|notifications):\w+)$/', $middleware, $match)) {
                        $this->assertStringContainsString($match[1], $operation['description'], $route->uri());
                    }
                }
            }
        }
        $this->assertSame('2.0.0', $spec['info']['version']);
    }

    public function test_browser_pages_and_assets_are_self_hosted_and_landing_keeps_json_compatibility(): void
    {
        $this->get('/')->assertOk()->assertSee('Know when it breaks.')->assertSee('/docs')->assertSee('/health/ready');
        $this->getJson('/')->assertOk()->assertJsonPath('data.version', '2.0.0');
        $docs = $this->get('/docs')->assertOk()->assertSee('/css/swagger-theme.css')->assertSee('Authorize')->assertSee('SwaggerUIBundle');
        $this->assertDoesNotMatchRegularExpression('/(?:src|href)="https?:\/\/[^"]+\.(?:js|css)/', $docs->getContent());
    }
}
