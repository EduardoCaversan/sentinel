<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\OrganizationApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token && str_starts_with($token, 'snl_')) {
            abort_unless(strlen($token) === 68 && ctype_xdigit(substr($token, 4)), 401, 'Invalid API key.');
            $key = OrganizationApiKey::where('secret_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
            abort_unless($key && (! $key->expires_at || $key->expires_at->isFuture()), 401, 'Invalid or expired API key.');
            abort_unless($request->route('organization') !== null, 403, 'Use a personal token for account and organization administration.');
            $request->attributes->set('api_key', $key);
            $request->setUserResolver(fn () => null);
            if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
                $key->update(['last_used_at' => now()]);
            }
        } else {
            $user = Auth::guard('sanctum')->user();
            abort_unless($user !== null, 401, 'Unauthenticated.');
            $request->setUserResolver(fn () => $user);
        }

        return $next($request);
    }
}
