<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'ok', 'version' => '1.0.0']]);
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        foreach (['database' => fn () => DB::select('SELECT 1'), 'redis' => fn () => Redis::connection()->ping()] as $name => $check) {
            try {
                $check();
                $checks[$name] = 'ok';
            } catch (Throwable) {
                $checks[$name] = 'unavailable';
            }
        }
        $ready = ! in_array('unavailable', $checks, true);

        return response()->json(['data' => ['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks]], $ready ? 200 : 503);
    }
}
