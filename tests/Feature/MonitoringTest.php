<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Jobs\CheckMonitorJob;
use App\Models\Monitor;
use App\Services\DnsResolver;
use App\Services\RecordCheck;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.215.14']);
    }

    private function runCheck(Monitor $monitor): void
    {
        app()->call([new CheckMonitorJob($monitor->id), 'handle']);
        $monitor->refresh();
    }

    public function test_success_pins_dns_disables_redirects_and_discards_body(): void
    {
        $monitor = Monitor::factory()->create();
        Http::fake(function ($request, $options) {
            $this->assertSame(['example.com:443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertTrue($options['verify']);
            $this->assertTrue(is_resource($options['sink']));
            $this->assertSame('GET', $request->method());

            return Http::response('sensitive body', 200);
        });
        $this->runCheck($monitor);
        $this->assertSame(MonitorStatus::Healthy, $monitor->status);
        $check = $monitor->checks()->first();
        $this->assertSame(CheckStatus::Success, $check->status);
        $this->assertGreaterThanOrEqual(0, $check->response_time_ms);
        $this->assertNotNull($monitor->last_checked_at);
        $this->assertStringNotContainsString('sensitive body', $check->toJson());
    }

    public function test_failure_and_recovery_thresholds_and_counter_resets(): void
    {
        $monitor = Monitor::factory()->create(['failure_threshold' => 3, 'recovery_threshold' => 2]);
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 200)->push('', 503)->push('', 503)->push('', 503)->push('', 503)->push('', 200)->push('', 503)->push('', 200)->push('', 200)]);
        $this->runCheck($monitor);
        $this->assertSame(MonitorStatus::Degraded, $monitor->status);
        $this->runCheck($monitor);
        $this->assertSame(0, $monitor->consecutive_failures);
        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $this->assertSame(0, $monitor->incidents()->count());
        $this->runCheck($monitor);
        $this->assertSame(MonitorStatus::Down, $monitor->status);
        $this->assertSame(1, $monitor->incidents()->count());
        $this->runCheck($monitor);
        $this->assertSame(1, $monitor->incidents()->count());
        $this->runCheck($monitor);
        $this->assertSame(MonitorStatus::Down, $monitor->status);
        $this->assertSame(1, $monitor->incidents()->first()->recovery_count);
        $this->runCheck($monitor);
        $this->assertSame(0, $monitor->consecutive_successes);
        $this->assertSame(0, $monitor->incidents()->first()->recovery_count);
        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $incident = $monitor->incidents()->first();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(5, $incident->failure_count);
        $this->assertSame(MonitorStatus::Healthy, $monitor->status);
    }

    public function test_duplicate_delivery_does_not_probe_or_persist_twice(): void
    {
        $monitor = Monitor::factory()->create(['failure_threshold' => 1]);
        Http::fake(['*' => Http::response('', 503)]);
        $job = new CheckMonitorJob($monitor->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->assertSame(1, $monitor->checks()->count());
        $this->assertSame(1, $monitor->incidents()->count());
    }

    public function test_lock_contention_does_not_send_request_and_releases_after_check(): void
    {
        $monitor = Monitor::factory()->create();
        Http::fake(['*' => Http::response('', 200)]);
        $lock = Cache::lock('monitor:'.$monitor->id, 90);
        $this->assertTrue($lock->get());
        $this->runCheck($monitor);
        Http::assertNothingSent();
        $lock->release();
        $this->runCheck($monitor);
        Http::assertSentCount(1);
        $next = Cache::lock('monitor:'.$monitor->id, 90);
        $this->assertTrue($next->get());
        $next->release();
    }

    public function test_timeout_and_network_failures_are_sanitized(): void
    {
        $monitor = Monitor::factory()->create();
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            if ($attempt++ === 0) {
                throw new ConnectionException('secret URL', 0, new ConnectException('secret URL', new Request('GET', 'https://example.com'), null, ['errno' => 28]));
            }
            throw new ConnectionException('network secret');
        });
        $this->runCheck($monitor);
        $this->assertSame(CheckStatus::Timeout, $monitor->checks()->first()->status);
        $this->assertStringNotContainsString('secret', $monitor->checks()->first()->error_message);
        $this->runCheck($monitor);
        $this->assertSame('connection', $monitor->checks()->latest('id')->first()->error_type);
    }

    public function test_rebinding_is_blocked_at_execution_time(): void
    {
        $monitor = Monitor::factory()->create();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['169.254.169.254']);
        Http::fake();
        $this->runCheck($monitor);
        Http::assertNothingSent();
        $this->assertSame('unsafe_target', $monitor->checks()->first()->error_type);
    }

    public function test_redirect_is_a_status_result_and_is_not_followed(): void
    {
        $monitor = Monitor::factory()->create();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254'])]);
        $this->runCheck($monitor);
        $this->assertSame(302, $monitor->checks()->first()->http_status_code);
        $this->assertSame('unexpected_status', $monitor->checks()->first()->error_type);
        Http::assertSentCount(1);
    }

    public function test_database_constraint_prevents_duplicate_open_incidents(): void
    {
        $monitor = Monitor::factory()->create();
        $values = ['started_at' => now(), 'failure_count' => 3];
        $monitor->incidents()->create($values);
        $this->expectException(QueryException::class);
        $monitor->incidents()->create($values);
    }

    public function test_new_outage_can_open_after_resolution(): void
    {
        $monitor = Monitor::factory()->create(['failure_threshold' => 1, 'recovery_threshold' => 1]);
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 200)->push('', 500)]);
        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $this->assertSame(2, $monitor->incidents()->count());
        $this->assertSame(1, $monitor->incidents()->where('status', 'open')->count());
    }

    public function test_in_flight_result_is_discarded_after_configuration_change(): void
    {
        $monitor = Monitor::factory()->create()->refresh();
        $result = ['status' => CheckStatus::Success, 'http_status_code' => 200, 'response_time_ms' => 5, 'checked_at' => now()];
        Monitor::whereKey($monitor->id)->increment('configuration_version');
        $this->assertNull(app(RecordCheck::class)->store($monitor, (string) Str::uuid(), $result));
        $this->assertSame(0, $monitor->checks()->count());
    }

    public function test_paused_and_deleted_monitors_are_not_checked(): void
    {
        Http::fake();
        $monitor = Monitor::factory()->create(['is_active' => false]);
        $this->runCheck($monitor);
        $id = $monitor->id;
        $monitor->delete();
        app()->call([new CheckMonitorJob($id), 'handle']);
        Http::assertNothingSent();
    }

    public function test_scheduler_dispatches_only_due_active_monitors(): void
    {
        Queue::fake();
        $due = Monitor::factory()->create();
        Monitor::factory()->create(['is_active' => false]);
        Monitor::factory()->create(['next_check_at' => now()->addHour()]);
        $this->artisan('monitors:dispatch')->assertSuccessful();
        Queue::assertPushed(CheckMonitorJob::class, fn ($job) => $job->monitorId === $due->id);
        Queue::assertPushed(CheckMonitorJob::class, 1);
    }
}
