<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Http\Requests\MonitorRequest;
use App\Http\Requests\PageRequest;
use App\Http\Resources\MonitorResource;
use App\Models\Monitor;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MonitorController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        return MonitorResource::collection($request->user()->monitors()->latest('id')->paginate($request->pageSize())->withQueryString());
    }

    public function store(MonitorRequest $request): MonitorResource
    {
        return new MonitorResource($request->user()->monitors()->create($request->validated())->refresh());
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
