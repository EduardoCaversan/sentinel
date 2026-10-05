<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationAccess
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $id = (string) $request->route('organization');
        $key = $request->attributes->get('api_key');
        if ($key) {
            abort_unless((string) $key->organization_id === (string) $id, 404);
            $organization = Organization::findOrFail($id);
            abort_unless(in_array($ability, $key->scopes, true), 403, 'API key scope required: '.$ability.'.');
        } else {
            $organization = $request->user()->organizations()->findOrFail($id);
            $role = $organization->roleFor($request->user());
            abort_unless($role?->allows($ability) === true, 403, 'Your organization role does not allow this action.');
            $request->attributes->set('organization_role', $role);
        }
        $request->attributes->set('organization', $organization);
        // The organization is request context; resource binding remains tenant scoped.
        $request->route()->forgetParameter('organization');

        return $next($request);
    }

    public static function organization(Request $request): Organization
    {
        return $request->attributes->get('organization') ?? $request->user()->personalOrganization();
    }
}
