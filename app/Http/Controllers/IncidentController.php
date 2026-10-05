<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\PageRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return IncidentResource::collection(Incident::whereHas('monitor', fn ($q) => $q->where('organization_id', OrganizationAccess::organization($request)->id))->latest('id')->paginate($request->pageSize()));
    }

    public function show(Incident $incident): IncidentResource
    {
        return new IncidentResource($incident);
    }

    public function acknowledge(Request $request, Incident $incident): IncidentResource
    {
        DB::transaction(function () use ($request, $incident): void {
            $locked = Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== IncidentStatus::Open, 409, 'Only open incidents can be acknowledged.');
            if (! $locked->acknowledged_at) {
                $locked->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()?->id, 'acknowledged_by_key' => $request->attributes->get('api_key')?->id]);
                $locked->events()->create(['type' => 'acknowledged', 'occurred_at' => now(), 'user_id' => $request->user()?->id, 'api_key_id' => $request->attributes->get('api_key')?->id, 'message' => 'Incident acknowledged.']);
            }
        });

        return new IncidentResource($incident->refresh());
    }

    public function note(Request $request, Incident $incident): JsonResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return new JsonResource($incident->events()->create([...$data, 'type' => 'note', 'occurred_at' => now(), 'user_id' => $request->user()?->id, 'api_key_id' => $request->attributes->get('api_key')?->id]));
    }

    public function timeline(PageRequest $request, Incident $incident): AnonymousResourceCollection
    {
        // Checks remain the source of truth; timeline combines them with human events.
        $end = $incident->resolved_at ?? now();
        $events = DB::table('incident_events')->where('incident_id', $incident->id)
            ->select('id', 'type', 'message', 'user_id', 'api_key_id', 'occurred_at')
            ->selectRaw('NULL as http_status_code, NULL as check_status, NULL as in_maintenance');
        $checks = DB::table('monitor_checks')->where('monitor_id', $incident->monitor_id)
            ->whereBetween('checked_at', [$incident->first_failed_at ?? $incident->started_at, $end])
            ->select('id')->selectRaw("'check' as type, NULL as message, NULL as user_id, NULL as api_key_id, checked_at as occurred_at, http_status_code, status as check_status, in_maintenance");
        $timeline = DB::query()->fromSub($events->unionAll($checks), 'timeline')->orderBy('occurred_at')->orderBy('type')->orderBy('id')->paginate($request->pageSize());

        $timeline->through(fn ($item) => [...(array) $item,
            'occurred_at' => Carbon::parse($item->occurred_at)->toISOString(),
            'in_maintenance' => $item->in_maintenance === null ? null : (bool) $item->in_maintenance,
        ]);

        return JsonResource::collection($timeline)->additional(['incident' => ['started_at' => $incident->started_at->toISOString(), 'resolved_at' => $incident->resolved_at?->toISOString(), 'duration_seconds' => $incident->durationSeconds()], 'meta' => ['check_history_retained_since' => now()->subDays(OrganizationAccess::organization($request)->retention_days)->toISOString()]]);
    }
}
