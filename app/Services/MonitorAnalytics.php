<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MonitorAnalytics
{
    public function calculate(Monitor $monitor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $all = $monitor->checks()->where('checked_at', '>=', $start)->where('checked_at', '<', $end);
        $total = (clone $all)->count();
        $eligible = (clone $all)->where('in_maintenance', false);
        $counts = (clone $eligible)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) as failed")->toBase()->first();
        $count = (int) $counts->total;
        $failed = (int) $counts->failed;
        $successful = (clone $eligible)->where('status', 'success');
        $latencyCount = $count - $failed;
        $uptime = $count > 0 ? round(100 * ($count - $failed) / $count, 5) : null;
        $percentiles = [];
        foreach (['p50' => 0.50, 'p95' => 0.95, 'p99' => 0.99] as $name => $percentile) {
            $percentiles[$name] = $latencyCount > 0 ? (int) (clone $successful)->orderBy('response_time_ms')->orderBy('id')->skip(max(0, (int) ceil($latencyCount * $percentile) - 1))->value('response_time_ms') : null;
        }
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $seconds = fn (string $column): string => $sqlite ? "CAST(strftime('%s', $column) AS INTEGER)" : "UNIX_TIMESTAMP($column)";
        $least = $sqlite ? 'MIN' : 'LEAST';
        $greatest = $sqlite ? 'MAX' : 'GREATEST';
        $overlap = $monitor->incidents()->where('started_at', '<', $end)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $start));
        $expression = "COALESCE(SUM($greatest(0, $least(COALESCE(".$seconds('resolved_at').", ?), ?) - $greatest(".$seconds('started_at').', ?))), 0) as downtime';
        $downtime = (clone $overlap)->selectRaw($expression, [$end->timestamp, $end->timestamp, $start->timestamp])->toBase()->first()->downtime;
        $mttr = $monitor->incidents()->where('resolved_at', '>=', $start)->where('resolved_at', '<', $end)
            ->selectRaw('AVG('.$seconds('resolved_at').' - '.$seconds('started_at').') as mttr')->toBase()->first()->mttr;
        $allowedFailures = $count * (1 - $monitor->slo_target / 100);
        $retainedSince = now()->subDays(min($monitor->organization->retention_days, config('sentinel.max_retention_days')));

        return [
            'start_at' => $start->toISOString(), 'end_at' => $end->toISOString(),
            'total_checks' => $total, 'eligible_checks' => $count, 'maintenance_checks' => $total - $count, 'failed_checks' => $failed,
            'uptime_percentage' => $uptime,
            'average_latency_ms' => ($average = (clone $successful)->avg('response_time_ms')) !== null ? round((float) $average, 2) : null,
            'latency_ms' => $percentiles,
            'incident_count' => $monitor->incidents()->where('started_at', '>=', $start)->where('started_at', '<', $end)->count(),
            'total_downtime_seconds' => (int) $downtime,
            'mttr_seconds' => $mttr !== null ? round((float) $mttr, 2) : null,
            'history_available_from' => $retainedSince->toISOString(), 'partial_retention' => $start->lt($retainedSince),
            'slo' => ['target_uptime' => $monitor->slo_target, 'current_uptime' => $uptime, 'error_budget_checks' => round($allowedFailures, 5), 'error_budget_used_checks' => $failed, 'error_budget_remaining_checks' => $count ? round(max(0, $allowedFailures - $failed), 5) : null, 'error_budget_remaining_percentage' => $count ? round(max(0, 100 * ($allowedFailures - $failed) / max(0.000001, $allowedFailures)), 5) : null],
        ];
    }
}
