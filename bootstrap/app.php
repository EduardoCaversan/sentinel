<?php

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\OrganizationAccess;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');
        $middleware->alias(['api.identity' => AuthenticateApi::class, 'organization' => OrganizationAccess::class]);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApi::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, OrganizationAccess::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*', 'health*') || $request->expectsJson());
        // Query exceptions embed SQL bindings, which can contain sensitive targets.
        $exceptions->report(function (QueryException $exception): void {
            Log::error('database.operation_failed', [
                'exception_class' => $exception::class,
                'sql_state' => $exception->errorInfo[0] ?? null,
            ]);
        })->stop();
        $exceptions->render(function (UniqueConstraintViolationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'A resource with these unique attributes already exists.'], 409);
            }
        });
    })->create();
