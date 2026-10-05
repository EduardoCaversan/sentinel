<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsRequest;
use App\Models\Monitor;
use App\Services\MonitorAnalytics;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function show(AnalyticsRequest $request, Monitor $monitor, MonitorAnalytics $analytics): JsonResponse
    {
        [$start, $end] = $request->range();

        return response()->json(['data' => $analytics->calculate($monitor, $start, $end)]);
    }
}
