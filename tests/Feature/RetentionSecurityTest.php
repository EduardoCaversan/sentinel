<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Jobs\CheckMonitorJob;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\StatusPage;
use App\Services\RecordCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\OrganizationTestCase;

class RetentionSecurityTest extends OrganizationTestCase
{
    public function test_pruning_respects_tenant_retention_and_keeps_incidents_and_recent_receipts(): void
    {
        $this->freezeTime();
        $this->organization->update(['retention_days' => 1]);
        $monitor = $this->monitor(['failure_threshold' => 1]);
        $id = (string) Str::uuid();
        app(RecordCheck::class)->store($monitor, $id, ['status' => CheckStatus::Failure, 'response_time_ms' => 5, 'checked_at' => now()->subDays(2)]);
        $other = Monitor::factory()->create();
        $other->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'success', 'response_time_ms' => 10, 'checked_at' => now()->subDays(2)]);
        $this->artisan('sentinel:prune')->assertSuccessful();
        $this->assertSame(0, $monitor->checks()->count());
        $this->assertSame(1, $other->checks()->count());
        $this->assertSame(1, $monitor->incidents()->count());
        $this->assertDatabaseHas('check_executions', ['execution_id' => $id]);
        $this->assertNull(app(RecordCheck::class)->store($monitor->refresh(), $id, ['status' => CheckStatus::Failure, 'response_time_ms' => 5, 'checked_at' => now()]));
        $this->assertSame(0, $monitor->checks()->count());
        DB::table('check_executions')->where('execution_id', $id)->update(['created_at' => now()->subDays(8)]);
        $this->artisan('sentinel:prune')->assertSuccessful();
        $this->assertDatabaseMissing('check_executions', ['execution_id' => $id]);
    }

    public function test_old_queue_messages_cannot_replay_after_history_retention(): void
    {
        $monitor = $this->monitor();
        $job = new CheckMonitorJob($monitor->id);
        $this->travel(2)->days();
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertSame(0, $monitor->checks()->count());
    }

    public function test_monitor_default_interval_obeys_config_and_transfer_size_is_bounded(): void
    {
        config(['sentinel.min_interval_seconds' => 300]);
        $this->postJson($this->api('/monitors'), ['name' => 'API', 'url' => 'https://example.com'])->assertCreated()->assertJsonPath('data.interval_seconds', 300);
        $this->postJson($this->api('/monitors'), ['name' => 'API', 'url' => 'https://example.com', 'interval_seconds' => 60])->assertUnprocessable();
        $monitor = $this->monitor();
        Http::fake(function ($request, $options) {
            $options['progress'](0, config('sentinel.response_max_bytes') + 1);

            return Http::response('', 200);
        });
        app()->call([new CheckMonitorJob($monitor->id), 'handle']);
        $this->assertSame('response_too_large', $monitor->checks()->first()->error_type);
        $this->assertGreaterThanOrEqual(299, now()->diffInSeconds($monitor->refresh()->next_check_at));
    }

    public function test_every_nested_operational_resource_is_tenant_scoped(): void
    {
        $foreign = Monitor::factory()->create();
        $channel = NotificationChannel::create(['organization_id' => $foreign->organization_id, 'name' => 'Private', 'type' => 'webhook', 'endpoint' => 'https://example.com/secret', 'events' => ['incident.opened'], 'is_active' => true]);
        $page = StatusPage::create(['organization_id' => $foreign->organization_id, 'name' => 'Private', 'slug' => 'private', 'is_published' => false]);
        $delivery = NotificationDelivery::create(['organization_id' => $foreign->organization_id, 'notification_channel_id' => $channel->id, 'monitor_id' => $foreign->id, 'public_id' => (string) Str::uuid(), 'execution_id' => (string) Str::uuid(), 'event' => 'incident.opened', 'payload' => [], 'next_attempt_at' => now()]);
        $this->patchJson($this->api("/notification-channels/{$channel->id}"), ['name' => 'Stolen'])->assertNotFound();
        $this->deleteJson($this->api("/notification-channels/{$channel->id}"))->assertNotFound();
        $this->getJson($this->api("/notification-deliveries/{$delivery->id}/attempts"))->assertNotFound();
        $this->getJson($this->api("/status-pages/{$page->id}"))->assertNotFound();
        $this->patchJson($this->api("/status-pages/{$page->id}"), ['is_published' => true])->assertNotFound();
        $this->getJson('/api/v2/status/private')->assertNotFound();
        $this->getJson($this->api('/notification-deliveries'))->assertOk()->assertJsonCount(0, 'data');
    }
}
