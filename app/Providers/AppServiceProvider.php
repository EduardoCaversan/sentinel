<?php

namespace App\Providers;

use App\Http\Middleware\OrganizationAccess;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\OrganizationApiKey;
use App\Models\OrganizationInvitation;
use App\Models\StatusPage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('monitor', fn (string $id) => OrganizationAccess::organization(request())->monitors()->findOrFail($id));
        foreach (['channel' => NotificationChannel::class, 'maintenance' => MaintenanceWindow::class, 'statusPage' => StatusPage::class, 'apiKey' => OrganizationApiKey::class, 'invitation' => OrganizationInvitation::class, 'delivery' => NotificationDelivery::class] as $parameter => $model) {
            Route::bind($parameter, fn (string $id) => $model::where('organization_id', OrganizationAccess::organization(request())->id)->findOrFail($id));
        }
        Route::bind('incident', fn (string $id) => Incident::whereHas('monitor', fn ($q) => $q->where('organization_id', OrganizationAccess::organization(request())->id))->findOrFail($id));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->attributes->get('api_key') ? 'org:'.$request->attributes->get('api_key')->organization_id : ($request->user()->id ?? $request->ip())));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('checks', fn (Request $request) => Limit::perMinute(6)->by('org:'.OrganizationAccess::organization($request)->id));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // Operational dashboard is intentionally unavailable to public API users.
        Horizon::auth(fn () => false);
    }
}
