<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MonitorActivityController;
use App\Http\Controllers\MonitorController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/v2.php';

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::apiResource('monitors', MonitorController::class)->except('update');
        Route::patch('monitors/{monitor}', [MonitorController::class, 'update']);
        Route::get('monitors/{monitor}/checks', [MonitorActivityController::class, 'checks']);
        Route::get('monitors/{monitor}/incidents', [MonitorActivityController::class, 'incidents']);
        Route::post('monitors/{monitor}/check', [MonitorActivityController::class, 'check']);
    });
});
