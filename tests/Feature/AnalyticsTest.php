<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MonitorAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\OrganizationTestCase;

class AnalyticsTest extends OrganizationTestCase
{
    public function test_metrics_percentiles_slo_and_clipped_downtime(): void
    {
        $this->freezeTime();
        $end = CarbonImmutable::now()->startOfSecond();
        $start = $end->subDay();
        $monitor = $this->monitor(['slo_target' => 99]);
        for ($i = 1; $i <= 100; $i++) {
            $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'success', 'response_time_ms' => $i, 'checked_at' => $start->addMinutes($i)]);
        }
        $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'failure', 'response_time_ms' => 999, 'checked_at' => $start->addHours(3)]);
        $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'failure', 'response_time_ms' => 999, 'in_maintenance' => true, 'checked_at' => $start->addHours(4)]);
        // Half-open range excludes the end boundary.
        $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'failure', 'response_time_ms' => 999, 'checked_at' => $end]);
        $monitor->incidents()->create(['status' => 'resolved', 'failure_count' => 3, 'started_at' => $start->subHour(), 'resolved_at' => $start->addHour()]);
        $monitor->incidents()->create(['status' => 'open', 'failure_count' => 3, 'started_at' => $end->subMinutes(30)]);
        $data = app(MonitorAnalytics::class)->calculate($monitor, $start, $end);
        $this->assertSame(102, $data['total_checks']);
        $this->assertSame(101, $data['eligible_checks']);
        $this->assertSame(1, $data['failed_checks']);
        $this->assertEqualsWithDelta(99.0099, $data['uptime_percentage'], 0.00001);
        $this->assertSame(50.5, $data['average_latency_ms']);
        $this->assertSame(['p50' => 50, 'p95' => 95, 'p99' => 99], $data['latency_ms']);
        $this->assertSame(5400, $data['total_downtime_seconds']);
        $this->assertSame(7200.0, $data['mttr_seconds']);
        $this->assertSame(1, $data['incident_count']);
        $this->assertSame(1.01, $data['slo']['error_budget_checks']);
        $this->assertSame(0.01, $data['slo']['error_budget_remaining_checks']);
        $this->getJson($this->api("/monitors/{$monitor->id}/analytics?period=custom&start_at=".urlencode($start->toISOString()).'&end_at='.urlencode($end->toISOString())))->assertOk()->assertJsonPath('data.total_checks', 102);
    }

    public function test_no_samples_is_unknown_and_budget_never_becomes_negative(): void
    {
        $monitor = $this->monitor();
        $path = $this->api("/monitors/{$monitor->id}/analytics");
        $this->getJson($path)->assertOk()->assertJsonPath('data.uptime_percentage', null)->assertJsonPath('data.latency_ms.p99', null)->assertJsonPath('data.slo.error_budget_remaining_percentage', null);
        $monitor->checks()->create(['execution_id' => (string) Str::uuid(), 'status' => 'failure', 'response_time_ms' => 20, 'checked_at' => now()->subMinute()]);
        $this->getJson($path)->assertOk()->assertJsonPath('data.uptime_percentage', 0)->assertJsonPath('data.slo.error_budget_remaining_checks', 0);
        $this->getJson("$path?period=custom")->assertUnprocessable();
        $this->getJson("$path?period=custom&start_at=2020-01-01&end_at=2021-01-01")->assertUnprocessable();
        $this->getJson("$path?period=365d")->assertUnprocessable();
        $this->organization->update(['retention_days' => 1]);
        $this->getJson("$path?period=7d")->assertOk()->assertJsonPath('data.partial_retention', true);
    }
}
