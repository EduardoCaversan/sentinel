<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Jobs\DeliverNotification;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\DnsResolver;
use App\Services\RecordCheck;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\OrganizationTestCase;

class NotificationTest extends OrganizationTestCase
{
    private function channel(string $type = 'webhook'): NotificationChannel
    {
        return NotificationChannel::create(['organization_id' => $this->organization->id, 'name' => 'On call', 'type' => $type, 'endpoint' => 'https://example.com/secret-hook', 'signing_secret' => str_repeat('s', 32), 'events' => NotificationChannel::EVENTS, 'is_active' => true]);
    }

    private function outage(): NotificationDelivery
    {
        $monitor = $this->monitor(['failure_threshold' => 1]);
        app(RecordCheck::class)->store($monitor, (string) Str::uuid(), ['status' => CheckStatus::Failure, 'response_time_ms' => 10, 'http_status_code' => 503, 'checked_at' => now()]);

        return NotificationDelivery::latest('id')->firstOrFail();
    }

    public function test_outbox_is_atomic_deduplicated_and_never_sends_inside_check_transaction(): void
    {
        $this->channel();
        $monitor = $this->monitor(['failure_threshold' => 1]);
        $id = (string) Str::uuid();
        $result = ['status' => CheckStatus::Failure, 'response_time_ms' => 5, 'checked_at' => now()];
        DB::beginTransaction();
        app(RecordCheck::class)->store($monitor, $id, $result);
        $this->assertSame(1, NotificationDelivery::count());
        Http::assertNothingSent();
        DB::rollBack();
        $this->assertSame(0, NotificationDelivery::count());
        $this->assertSame(0, $monitor->checks()->count());
        app(RecordCheck::class)->store($monitor, $id, $result);
        app(RecordCheck::class)->store($monitor, $id, $result);
        $this->assertSame(1, NotificationDelivery::count());
        $this->artisan('notifications:dispatch')->assertSuccessful();
        Queue::assertPushed(DeliverNotification::class, 1);
    }

    public function test_webhook_is_signed_pinned_and_secrets_are_encrypted_and_hidden(): void
    {
        $channel = $this->channel();
        $raw = DB::table('notification_channels')->first();
        $this->assertStringNotContainsString('secret-hook', $raw->endpoint);
        $this->assertNotSame(str_repeat('s', 32), $raw->signing_secret);
        $this->getJson($this->api('/notification-channels'))->assertOk()->assertJsonMissingPath('data.0.endpoint')->assertJsonMissingPath('data.0.signing_secret');
        $delivery = $this->outage();
        Http::fake(function ($request, $options) use ($delivery) {
            $this->assertSame(['example.com:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame('', $options['proxy']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame($delivery->public_id, $request->header('Idempotency-Key')[0]);
            $signature = 'sha256='.hash_hmac('sha256', $request->header('X-Sentinel-Timestamp')[0].'.'.$request->body(), str_repeat('s', 32));
            $this->assertSame($signature, $request->header('X-Sentinel-Signature')[0]);
            $this->assertStringNotContainsString('secret-hook', $request->body());

            return Http::response('', 204);
        });
        app()->call([new DeliverNotification($delivery->id), 'handle']);
        app()->call([new DeliverNotification($delivery->id), 'handle']);
        Http::assertSentCount(1);
        $this->assertSame('delivered', $delivery->refresh()->status);
        $this->getJson($this->api("/notification-deliveries/{$delivery->id}/attempts"))->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('secret-hook', $delivery->toJson());
    }

    public function test_transient_failures_retry_with_backoff_and_do_not_affect_incident(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->channel();
        $delivery = $this->outage();
        Http::fake(['*' => Http::sequence()->push('secret response', 503)->push('', 429)->push('', 204)]);
        $job = new DeliverNotification($delivery->id);
        app()->call([$job, 'handle']);
        $this->assertSame('retrying', $delivery->refresh()->status);
        $this->assertSame(30, (int) round(now()->diffInSeconds($delivery->next_attempt_at)));
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->travel(30)->seconds();
        app()->call([$job, 'handle']);
        $this->assertSame(120, (int) round(now()->diffInSeconds($delivery->refresh()->next_attempt_at)));
        $this->travel(120)->seconds();
        app()->call([$job, 'handle']);
        $this->assertSame('delivered', $delivery->refresh()->status);
        $this->assertSame(3, $delivery->deliveryAttempts()->count());
        $this->assertDatabaseHas('incidents', ['monitor_id' => $delivery->monitor_id, 'status' => 'open']);
        $this->assertStringNotContainsString('secret response', $delivery->deliveryAttempts->toJson());
    }

    public function test_retry_exhaustion_redirects_disabled_channels_and_execution_lock(): void
    {
        $this->travelTo(now()->startOfSecond());
        $channel = $this->channel();
        $delivery = $this->outage();
        Http::fake(['*' => Http::response('', 500)]);
        $lock = Cache::lock('delivery:'.$delivery->id, 90);
        $lock->get();
        app()->call([new DeliverNotification($delivery->id), 'handle']);
        Http::assertNothingSent();
        $lock->release();
        foreach (range(1, 5) as $attempt) {
            app()->call([new DeliverNotification($delivery->id), 'handle']);
            $this->travel(31)->minutes();
        }
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertSame(5, $delivery->attempts);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254'])]);
        $redirect = $this->outage();
        app()->call([new DeliverNotification($redirect->id), 'handle']);
        $this->assertSame('failed', $redirect->refresh()->status);
        $this->assertSame(1, $redirect->attempts);
        $cancelled = $this->outage();
        $channel->update(['is_active' => false]);
        app()->call([new DeliverNotification($cancelled->id), 'handle']);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
    }

    public function test_rebinding_blocks_delivery_and_does_not_leak_transport_error(): void
    {
        $this->channel();
        $delivery = $this->outage();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['169.254.169.254']);
        Http::fake();
        app()->call([new DeliverNotification($delivery->id), 'handle']);
        Http::assertNothingSent();
        $this->assertSame('unsafe_target', $delivery->deliveryAttempts()->first()->error_type);
        $this->assertSame('failed', $delivery->refresh()->status);
    }

    public function test_slack_discord_payloads_and_channel_validation(): void
    {
        foreach (['slack', 'discord'] as $type) {
            Http::swap(new Factory);
            $channel = $this->channel($type);
            $delivery = $this->outage();
            Http::fake(function ($request) use ($type) {
                if ($type === 'slack') {
                    $this->assertSame('plain_text', $request['blocks'][0]['text']['type']);
                } else {
                    $this->assertSame([], $request['allowed_mentions']['parse']);
                }

                return Http::response('', 200);
            });
            app()->call([new DeliverNotification($delivery->id), 'handle']);
            $this->assertSame('delivered', $delivery->refresh()->status);
            $channel->delete();
        }
        $payload = ['name' => 'Hook', 'type' => 'webhook', 'endpoint' => 'https://example.com/hook', 'events' => ['incident.opened']];
        $this->postJson($this->api('/notification-channels'), $payload)->assertCreated()->assertJsonMissingPath('data.endpoint');
        $this->postJson($this->api('/notification-channels'), [...$payload, 'endpoint' => 'http://example.com'])->assertUnprocessable();
        $this->postJson($this->api('/notification-channels'), [...$payload, 'endpoint' => 'https://127.0.0.1'])->assertUnprocessable();
        $this->postJson($this->api('/notification-channels'), [...$payload, 'type' => 'slack'])->assertUnprocessable();
        config(['sentinel.quotas.channels' => 1]);
        $this->postJson($this->api('/notification-channels'), $payload)->assertConflict();
    }
}
