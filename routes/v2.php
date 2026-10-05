<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MonitorActivityController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('v2.auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('status/{slug}', [PublicStatusController::class, 'show'])->middleware('throttle:public');
    Route::middleware('api.identity')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);
        Route::post('invitations/accept', [MemberController::class, 'accept'])->middleware('throttle:auth');
        $routes = [
            ['get', '', OrganizationController::class, 'show', 'organization:read'],
            ['patch', '', OrganizationController::class, 'update', 'organization:manage'],
            ['post', '/transfer-ownership', OrganizationController::class, 'transfer', 'ownership:transfer'],
            ['post', '/leave', MemberController::class, 'leave', 'organization:read'],
            ['get', '/members', MemberController::class, 'index', 'members:manage'],
            ['patch', '/members/{member}', MemberController::class, 'update', 'members:manage'],
            ['delete', '/members/{member}', MemberController::class, 'destroy', 'members:manage'],
            ['get', '/invitations', MemberController::class, 'invitations', 'members:manage'],
            ['post', '/invitations', MemberController::class, 'invite', 'members:manage'],
            ['delete', '/invitations/{invitation}', MemberController::class, 'cancel', 'members:manage'],
            ['get', '/api-keys', ApiKeyController::class, 'index', 'keys:manage'],
            ['post', '/api-keys', ApiKeyController::class, 'store', 'keys:manage'],
            ['delete', '/api-keys/{apiKey}', ApiKeyController::class, 'destroy', 'keys:manage'],
            ['get', '/monitors', MonitorController::class, 'index', 'monitors:read'],
            ['post', '/monitors', MonitorController::class, 'store', 'monitors:write'],
            ['get', '/monitors/{monitor}', MonitorController::class, 'show', 'monitors:read'],
            ['patch', '/monitors/{monitor}', MonitorController::class, 'update', 'monitors:write'],
            ['delete', '/monitors/{monitor}', MonitorController::class, 'destroy', 'monitors:write'],
            ['get', '/monitors/{monitor}/checks', MonitorActivityController::class, 'checks', 'monitors:read'],
            ['get', '/monitors/{monitor}/incidents', MonitorActivityController::class, 'incidents', 'incidents:read'],
            ['get', '/monitors/{monitor}/analytics', AnalyticsController::class, 'show', 'analytics:read'],
            ['get', '/incidents', IncidentController::class, 'index', 'incidents:read'],
            ['get', '/incidents/{incident}', IncidentController::class, 'show', 'incidents:read'],
            ['post', '/incidents/{incident}/acknowledge', IncidentController::class, 'acknowledge', 'incidents:write'],
            ['post', '/incidents/{incident}/notes', IncidentController::class, 'note', 'incidents:write'],
            ['get', '/incidents/{incident}/timeline', IncidentController::class, 'timeline', 'incidents:read'],
            ['get', '/maintenance', MaintenanceController::class, 'index', 'maintenance:read'],
            ['post', '/maintenance', MaintenanceController::class, 'store', 'maintenance:write'],
            ['delete', '/maintenance/{maintenance}', MaintenanceController::class, 'destroy', 'maintenance:write'],
            ['get', '/notification-channels', NotificationController::class, 'index', 'notifications:read'],
            ['post', '/notification-channels', NotificationController::class, 'store', 'notifications:write'],
            ['patch', '/notification-channels/{channel}', NotificationController::class, 'update', 'notifications:write'],
            ['delete', '/notification-channels/{channel}', NotificationController::class, 'destroy', 'notifications:write'],
            ['get', '/notification-deliveries', NotificationController::class, 'deliveries', 'notifications:read'],
            ['get', '/notification-deliveries/{delivery}/attempts', NotificationController::class, 'attempts', 'notifications:read'],
            ['get', '/status-pages', StatusPageController::class, 'index', 'status-pages:read'],
            ['post', '/status-pages', StatusPageController::class, 'store', 'status-pages:write'],
            ['get', '/status-pages/{statusPage}', StatusPageController::class, 'show', 'status-pages:read'],
            ['patch', '/status-pages/{statusPage}', StatusPageController::class, 'update', 'status-pages:write'],
            ['delete', '/status-pages/{statusPage}', StatusPageController::class, 'destroy', 'status-pages:write'],
        ];
        foreach ($routes as [$method, $path, $controller, $action, $ability]) {
            Route::$method('organizations/{organization}'.$path, [$controller, $action])->middleware('organization:'.$ability);
        }
        Route::post('organizations/{organization}/monitors/{monitor}/check', [MonitorActivityController::class, 'check'])
            ->middleware('organization:monitors:write');
    });
});
