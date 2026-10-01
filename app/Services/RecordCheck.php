<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordCheck
{
    public function store(Monitor $snapshot, string $executionId, array $result): ?MonitorCheck
    {
        return DB::transaction(function () use ($snapshot, $executionId, $result): ?MonitorCheck {
            $monitor = Monitor::query()->lockForUpdate()->find($snapshot->id);
            if (! $monitor || ! $monitor->is_active || $monitor->configuration_version !== $snapshot->configuration_version) {
                return null;
            }
            if (MonitorCheck::where('execution_id', $executionId)->exists()) {
                return null;
            }
            $check = $monitor->checks()->create([...$result, 'execution_id' => $executionId]);
            $incident = $monitor->incidents()->where('status', IncidentStatus::Open)->first();
            $transition = null;
            if ($check->status === CheckStatus::Success) {
                $monitor->consecutive_failures = 0;
                $monitor->consecutive_successes = min(255, $monitor->consecutive_successes + 1);
                if ($incident) {
                    $incident->recovery_count = $monitor->consecutive_successes;
                    if ($monitor->consecutive_successes >= $monitor->recovery_threshold) {
                        $incident->status = IncidentStatus::Resolved;
                        $incident->resolved_at = $check->checked_at;
                        $monitor->status = MonitorStatus::Healthy;
                        $transition = 'incident.resolved';
                    }
                    $incident->save();
                } else {
                    $monitor->status = MonitorStatus::Healthy;
                }
            } else {
                $monitor->consecutive_successes = 0;
                $monitor->consecutive_failures = min(255, $monitor->consecutive_failures + 1);
                if ($incident) {
                    $incident->increment('failure_count');
                    $incident->update(['recovery_count' => 0]);
                } elseif ($monitor->consecutive_failures >= $monitor->failure_threshold) {
                    $incident = $monitor->incidents()->create(['status' => IncidentStatus::Open, 'started_at' => $check->checked_at, 'failure_count' => $monitor->consecutive_failures]);
                    $monitor->status = MonitorStatus::Down;
                    $transition = 'incident.opened';
                } else {
                    $monitor->status = MonitorStatus::Degraded;
                }
            }
            $monitor->last_checked_at = $check->checked_at;
            $monitor->next_check_at = now()->addSeconds($monitor->interval_seconds);
            $monitor->save();
            $context = ['monitor_id' => $monitor->id, 'check_id' => $check->id, 'incident_id' => $incident?->id, 'execution_id' => $executionId, 'duration_ms' => $check->response_time_ms, 'http_status' => $check->http_status_code, 'status' => $check->status->value];
            DB::afterCommit(function () use ($context, $transition): void {
                Log::info('monitor.checked', $context);
                if ($transition) {
                    Log::notice($transition, $context);
                }
            });

            return $check;
        }, 3);
    }
}
