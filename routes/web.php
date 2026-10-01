<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['data' => ['name' => 'Sentinel API', 'description' => 'Monitoring & Incident Management', 'status' => 'operational', 'version' => '1.0.0', 'documentation' => '/docs', 'health' => '/health', 'api' => '/api/v1']]));
Route::get('/health', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready'])->middleware('throttle:60,1');
Route::view('/docs', 'docs');
