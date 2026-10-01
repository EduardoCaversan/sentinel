<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CheckMonitorJob;
use App\Models\Monitor;
use App\Models\User;
use App\Services\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.215.14']);
    }

    public function test_create_update_list_and_delete(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $created = $this->postJson('/api/v1/monitors', ['name' => 'Payment API', 'url' => 'https://example.com', 'user_id' => 999, 'status' => 'down']);
        $created->assertCreated()->assertJsonPath('data.status', 'unknown')->assertJsonPath('data.failure_threshold', 3);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('monitors', ['id' => $id, 'user_id' => $user->id]);
        $this->getJson('/api/v1/monitors/'.$id)->assertOk();
        $this->patchJson('/api/v1/monitors/'.$id, ['name' => 'Payments', 'failure_threshold' => 4])->assertOk()->assertJsonPath('data.name', 'Payments');
        Monitor::factory()->count(4)->for($user)->create();
        Monitor::factory()->create();
        $this->getJson('/api/v1/monitors?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->deleteJson('/api/v1/monitors/'.$id)->assertNoContent();
        $this->getJson('/api/v1/monitors/'.$id)->assertNotFound();
    }

    public function test_ownership_for_every_monitor_route(): void
    {
        $other = Monitor::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        foreach (['', '/checks', '/incidents'] as $suffix) {
            $this->getJson('/api/v1/monitors/'.$other->id.$suffix)->assertNotFound();
        }
        $this->patchJson('/api/v1/monitors/'.$other->id, ['name' => 'stolen'])->assertNotFound();
        $this->deleteJson('/api/v1/monitors/'.$other->id)->assertNotFound();
        $this->postJson('/api/v1/monitors/'.$other->id.'/check')->assertNotFound();
        $this->getJson('/api/v1/monitors')->assertJsonCount(0, 'data');
    }

    public function test_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/monitors', ['name' => '', 'url' => 'http://127.0.0.1', 'method' => 'POST', 'interval_seconds' => 61, 'timeout_seconds' => 16, 'failure_threshold' => 0, 'recovery_threshold' => 11, 'expected_status_code' => 600])->assertUnprocessable()->assertJsonValidationErrors(['name', 'url', 'method', 'interval_seconds', 'timeout_seconds', 'failure_threshold', 'recovery_threshold', 'expected_status_code']);
        $this->getJson('/api/v1/monitors?per_page=1000')->assertUnprocessable();
    }

    public function test_manual_check_is_queued_and_inactive_monitor_is_rejected(): void
    {
        Queue::fake();
        $monitor = Monitor::factory()->create();
        Sanctum::actingAs($monitor->user);
        $this->postJson('/api/v1/monitors/'.$monitor->id.'/check')->assertAccepted();
        Queue::assertPushed(CheckMonitorJob::class, fn ($job) => $job->monitorId === $monitor->id);
        $monitor->update(['is_active' => false]);
        $this->postJson('/api/v1/monitors/'.$monitor->id.'/check')->assertStatus(409);
    }
}
