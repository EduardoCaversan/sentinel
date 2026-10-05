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
            if (DB::table('check_executions')->where('execution_id', $executionId)->exists() || MonitorCheck::where('execution_id', $executionId)->exists()) {
                return null;
            }
            DB::table('check_executions')->insert(['execution_id' => $executionId, 'monitor_id' => $monitor->id, 'created_at' => now()]);
            $maintenance = $monitor->maintenanceWindows()->where('start_at', '<=', $result['checked_at'])->where('end_at', '>', $result['checked_at'])->exists();
            $crossedMaintenance = $monitor->maintenanceWindows()->where('start_at', '<=', $result['checked_at'])->where('end_at', '>', $monitor->last_checked_at ?? $result['checked_at'])->exists();
            $previousStatus = $monitor->status;
            if ($crossedMaintenance) {
                $monitor->consecutive_failures = 0;
                $monitor->consecutive_successes = 0;
            }
            $check = $monitor->checks()->create([...$result, 'execution_id' => $executionId, 'in_maintenance' => $maintenance]);
            $incident = $monitor->incidents()->where('status', IncidentStatus::Open)->first();
            $transition = null;
            if ($crossedMaintenance && $incident) {
                $incident->update(['recovery_count' => 0]);
            }
            if ($maintenance) {
                $monitor->status = $incident ? MonitorStatus::Down : MonitorStatus::Unknown;
            } elseif ($check->status === CheckStatus::Success) {
                $monitor->consecutive_failures = 0;
                $monitor->consecutive_successes = min(255, $monitor->consecutive_successes + 1);
                if ($incident) {
                    if ($monitor->consecutive_successes === 1) {
                        $incident->events()->create(['type' => 'recovery_started', 'message' => 'First successful check during the outage.', 'occurred_at' => $check->checked_at]);
                    }
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
                    if ($incident->recovery_count > 0) {
                        $incident->events()->create(['type' => 'recovery_interrupted', 'message' => 'A failed check reset the recovery streak.', 'occurred_at' => $check->checked_at]);
                    }
                    $incident->increment('failure_count');
                    $incident->update(['recovery_count' => 0]);
                } elseif ($monitor->consecutive_failures >= $monitor->failure_threshold) {
                    $firstFailed = $monitor->checks()->latest('id')->skip($monitor->consecutive_failures - 1)->value('checked_at');
                    $incident = $monitor->incidents()->create(['status' => IncidentStatus::Open, 'first_failed_at' => $firstFailed, 'started_at' => $check->checked_at, 'failure_count' => $monitor->consecutive_failures]);
                    $monitor->status = MonitorStatus::Down;
                    $transition = 'incident.opened';
                } else {
                    $monitor->status = MonitorStatus::Degraded;
                }
            }
            $monitor->last_checked_at = $check->checked_at;
            $monitor->next_check_at = now()->addSeconds(max($monitor->interval_seconds, config('sentinel.min_interval_seconds')));
            $monitor->save();
            if ($transition) {
                $incident->events()->create(['type' => $transition, 'message' => $transition === 'incident.opened' ? 'Failure threshold reached; incident opened.' : 'Recovery threshold reached; incident resolved.', 'occurred_at' => $check->checked_at]);
            }
            if (! $maintenance) {
                $events = array_filter([$transition]);
                if ($monitor->status === MonitorStatus::Degraded && $previousStatus !== MonitorStatus::Degraded) {
                    $events[] = 'monitor.degraded';
                }
                if ($monitor->status === MonitorStatus::Healthy && in_array($previousStatus, [MonitorStatus::Down, MonitorStatus::Degraded], true)) {
                    $events[] = 'monitor.recovered';
                }
                app(NotificationOutbox::class)->record($monitor, $check, $incident, $events);
            }
            $context = ['organization_id' => $monitor->organization_id, 'monitor_id' => $monitor->id, 'check_id' => $check->id, 'incident_id' => $incident?->id, 'execution_id' => $executionId, 'duration_ms' => $check->response_time_ms, 'http_status' => $check->http_status_code, 'status' => $check->status->value];
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
