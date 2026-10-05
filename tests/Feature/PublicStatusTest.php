<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\OrganizationTestCase;

class PublicStatusTest extends OrganizationTestCase
{
    public function test_public_payload_contains_only_explicitly_published_information(): void
    {
        $monitor = $this->monitor(['name' => 'SECRET internal name', 'url' => 'https://example.com/private?token=SECRET', 'status' => 'healthy']);
        $hidden = $this->monitor(['name' => 'SECRET hidden component', 'status' => 'down']);
        $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'success', 'response_time_ms' => 10, 'checked_at' => now()->subMinute()]);
        $monitor->incidents()->create(['status' => 'resolved', 'failure_count' => 3, 'started_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30)])->events()->create(['type' => 'note', 'message' => 'SECRET operator notes', 'occurred_at' => now()]);
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'SECRET maintenance', 'start_at' => now()->addHour(), 'end_at' => now()->addHours(2)]);
        $window->monitors()->attach([$monitor->id, $hidden->id]);
        $page = $this->postJson($this->api('/status-pages'), ['name' => 'Service status', 'slug' => 'public-service', 'components' => [['monitor_id' => $monitor->id, 'name' => 'Payments']], 'is_published' => true])->assertCreated()->json('data');
        $public = $this->getJson('/api/v2/status/public-service')->assertOk()->assertJsonPath('data.status', 'healthy')->assertJsonPath('data.components.0.uptime_percentage_24h', 100)->assertJsonCount(1, 'data.components')->assertDontSee('SECRET');
        foreach (['monitor_id', 'organization_id', 'user_id', 'url', 'acknowledged_by'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $public->getContent());
        }
        $this->patchJson($this->api('/status-pages/'.$page['id']), ['is_published' => false])->assertOk();
        $this->getJson('/api/v2/status/public-service')->assertNotFound();
    }

    public function test_component_validation_quotas_and_html_escape_user_content(): void
    {
        $monitor = $this->monitor();
        $payload = ['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>', 'slug' => 'escaped-page', 'is_published' => true, 'components' => [['monitor_id' => $monitor->id, 'name' => '<script>component</script>']]];
        $id = $this->postJson($this->api('/status-pages'), $payload)->assertCreated()->json('data.id');
        $this->get('/status/escaped-page')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->postJson($this->api('/status-pages'), [...$payload, 'slug' => 'other-page', 'components' => [['monitor_id' => Monitor::factory()->create()->id, 'name' => 'Foreign']]])->assertUnprocessable();
        config(['sentinel.quotas.status_pages' => 1]);
        $this->postJson($this->api('/status-pages'), [...$payload, 'slug' => 'second-page'])->assertConflict();
        $this->deleteJson($this->api("/status-pages/$id"))->assertNoContent();
        $this->getJson('/api/v2/status/escaped-page')->assertNotFound();
    }

    public function test_maintenance_and_degradation_aggregate_without_exposing_descriptions(): void
    {
        $monitor = $this->monitor(['status' => 'healthy']);
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'Private', 'start_at' => now()->subMinute(), 'end_at' => now()->addHour()]);
        $window->monitors()->attach($monitor);
        $this->postJson($this->api('/status-pages'), ['name' => 'Status', 'slug' => 'maintenance-page', 'components' => [['monitor_id' => $monitor->id, 'name' => 'API']], 'is_published' => true])->assertCreated();
        $this->getJson('/api/v2/status/maintenance-page')->assertOk()->assertJsonPath('data.status', 'maintenance')->assertDontSee('Private');
        $window->delete();
        $monitor->forceFill(['status' => 'degraded'])->save();
        Cache::forget('status:maintenance-page');
        $this->getJson('/api/v2/status/maintenance-page')->assertOk()->assertJsonPath('data.status', 'degraded');
    }
}
