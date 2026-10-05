<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\MonitorRequest;
use App\Http\Requests\PageRequest;
use App\Http\Resources\MonitorResource;
use App\Models\Monitor;
use App\Services\Quota;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MonitorController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return MonitorResource::collection(OrganizationAccess::organization($request)->monitors()->latest('id')->paginate($request->pageSize())->withQueryString());
    }

    public function store(MonitorRequest $request): MonitorResource
    {
        $organization = OrganizationAccess::organization($request);
        $monitor = app(Quota::class)->create($organization, 'monitors', function () use ($organization, $request): Monitor {
            $monitor = new Monitor(['interval_seconds' => config('sentinel.min_interval_seconds'), ...$request->validated()]);
            $monitor->organization_id = $organization->id;
            $monitor->user_id = $request->user()?->id;
            $monitor->save();

            return $monitor->refresh();
        });

        return new MonitorResource($monitor);
    }

    public function show(Monitor $monitor): MonitorResource
    {
        return new MonitorResource($monitor);
    }

    public function update(MonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $monitor = DB::transaction(function () use ($request, $monitor): Monitor {
            $locked = Monitor::lockForUpdate()->findOrFail($monitor->id);
            $locked->fill($request->validated());
            if ($locked->isDirty()) {
                $locked->configuration_version++;
                $locked->consecutive_failures = 0;
                $locked->consecutive_successes = 0;
                $open = $locked->incidents()->where('status', IncidentStatus::Open)->first();
                $open?->update(['recovery_count' => 0]);
                $locked->status = $open ? MonitorStatus::Down : MonitorStatus::Unknown;
                $locked->next_check_at = null;
                $locked->save();
            }

            return $locked;
        });

        return new MonitorResource($monitor);
    }

    public function destroy(Monitor $monitor): Response
    {
        $monitor->delete();

        return response()->noContent();
    }
}
