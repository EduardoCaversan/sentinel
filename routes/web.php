<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicStatusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => $request->expectsJson()
    ? response()->json(['data' => ['name' => 'Sentinel API', 'description' => 'Monitoring & Incident Management', 'status' => 'operational', 'version' => config('sentinel.version'), 'documentation' => '/docs', 'health' => '/health', 'readiness' => '/health/ready', 'api' => '/api/v2']])
    : view('landing'));
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready'])->middleware('throttle:60,1');
Route::view('/docs', 'docs');
Route::get('/status/{slug}', [PublicStatusController::class, 'page'])->middleware('throttle:public');
