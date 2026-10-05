<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PageRequest;
use App\Http\Resources\CheckResource;
use App\Http\Resources\IncidentResource;
use App\Jobs\CheckMonitorJob;
use App\Models\Monitor;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\RateLimiter;

class MonitorActivityController extends Controller
{
    public function checks(PageRequest $request, Monitor $monitor): AnonymousResourceCollection
    {
        return CheckResource::collection($monitor->checks()->latest('id')->paginate($request->pageSize())->withQueryString());
    }

    public function incidents(PageRequest $request, Monitor $monitor): AnonymousResourceCollection
    {
        return IncidentResource::collection($monitor->incidents()->latest('id')->paginate($request->pageSize())->withQueryString());
    }

    public function check(Monitor $monitor): JsonResponse
    {
        $rateKey = 'manual-check:'.$monitor->organization_id;
        if (RateLimiter::tooManyAttempts($rateKey, 6)) {
            throw new HttpResponseException(response()->json(['message' => 'Too many manual checks for this organization.'], 429, ['Retry-After' => RateLimiter::availableIn($rateKey)]));
        }
        RateLimiter::hit($rateKey, 60);
        abort_unless($monitor->is_active, 409, 'Activate the monitor before requesting a check.');
        CheckMonitorJob::dispatch($monitor->id);

        return response()->json(['data' => ['monitor_id' => $monitor->id, 'message' => 'Check requested. An existing queued or running check may satisfy this request.']], 202);
    }
}
