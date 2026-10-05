<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\MonitorCheck;
use App\Models\StatusPage;

class PublicStatus
{
    public function data(StatusPage $page): array
    {
        $monitors = $page->monitors()->get();
        $ids = $monitors->modelKeys();
        $aliases = $monitors->mapWithKeys(fn ($monitor) => [$monitor->id => $monitor->pivot->getAttribute('name')]);
        $windows = MaintenanceWindow::whereHas('monitors', fn ($q) => $q->whereIn('monitors.id', $ids))
            ->where('end_at', '>', now()->subDay())->with(['monitors' => fn ($q) => $q->whereIn('monitors.id', $ids)->select('monitors.id')])->orderBy('start_at')->limit(20)->get();
        $active = MaintenanceWindow::where('start_at', '<=', now())->where('end_at', '>', now())->whereHas('monitors', fn ($q) => $q->whereIn('monitors.id', $ids))
            ->with(['monitors' => fn ($q) => $q->whereIn('monitors.id', $ids)->select('monitors.id')])->get()->flatMap(fn ($w) => $w->monitors->modelKeys())->all();
        $availability = MonitorCheck::whereIn('monitor_id', $ids)->where('checked_at', '>=', now()->subDay())->where('in_maintenance', false)
            ->groupBy('monitor_id')->selectRaw("monitor_id, COUNT(*) as total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes")->toBase()->get()->keyBy('monitor_id');
        $components = $monitors->map(function ($monitor) use ($availability, $active): array {
            $sample = $availability->get($monitor->id);
            $status = in_array($monitor->id, $active, true) ? 'maintenance' : ($monitor->is_active ? $monitor->status->value : 'unknown');

            return ['name' => $monitor->pivot->getAttribute('name'), 'status' => $status, 'uptime_percentage_24h' => $sample ? round(100 * $sample->successes / $sample->total, 3) : null];
        })->all();
        $states = array_column($components, 'status');
        $overall = 'unknown';
        foreach (['down', 'degraded', 'maintenance', 'unknown', 'healthy'] as $state) {
            if (in_array($state, $states, true)) {
                $overall = $state;
                break;
            }
        }
        $incidents = Incident::whereIn('monitor_id', $ids)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays(7)))
            ->latest('started_at')->limit(20)->get()->map(fn ($incident) => ['component' => $aliases[$incident->monitor_id], 'status' => $incident->status->value, 'started_at' => $incident->started_at->toISOString(), 'resolved_at' => $incident->resolved_at?->toISOString(), 'duration_seconds' => $incident->durationSeconds()])->all();

        return [
            'name' => $page->name, 'description' => $page->description, 'status' => $overall,
            'components' => $components, 'incidents' => $incidents,
            'maintenance' => $windows->map(fn ($window) => ['start_at' => $window->start_at->toISOString(), 'end_at' => $window->end_at->toISOString(), 'status' => $window->state(), 'components' => $window->monitors->map(fn ($m) => $aliases[$m->id])->all()])->all(),
            'updated_at' => now()->toISOString(),
        ];
    }
}
