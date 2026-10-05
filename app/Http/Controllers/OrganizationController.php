<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\OrganizationRequest;
use App\Http\Requests\PageRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        $request->user()->personalOrganization();

        return OrganizationResource::collection($request->user()->organizations()->orderBy('organizations.id')->paginate($request->pageSize()));
    }

    public function store(OrganizationRequest $request): OrganizationResource
    {
        $organization = DB::transaction(function () use ($request): Organization {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if(Organization::where('owner_id', $user->id)->whereNull('personal_user_id')->count() >= config('sentinel.quotas.organizations'), 409, 'Organization ownership quota reached.');
            $organization = new Organization($request->validated());
            $organization->retention_days ??= config('sentinel.retention_days');
            $organization->owner_id = $user->id;
            $organization->save();
            $organization->members()->attach($user->id, ['role' => 'owner']);

            return $organization;
        });

        return new OrganizationResource($organization);
    }

    public function show(Request $request): OrganizationResource
    {
        return new OrganizationResource(OrganizationAccess::organization($request));
    }

    public function update(OrganizationRequest $request): OrganizationResource
    {
        $organization = OrganizationAccess::organization($request);
        $organization->update($request->validated());

        return new OrganizationResource($organization);
    }

    public function transfer(Request $request): OrganizationResource
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $organization = OrganizationAccess::organization($request);
        DB::transaction(function () use ($organization, $data, $request): void {
            $locked = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->owner_id === $request->user()->id, 403);
            abort_if($locked->personal_user_id !== null, 409, 'Personal organization ownership cannot be transferred.');
            abort_if($data['user_id'] === $locked->owner_id, 409, 'User already owns this organization.');
            $member = $locked->members()->findOrFail((int) $data['user_id']);
            User::whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_if(Organization::where('owner_id', $member->id)->whereNull('personal_user_id')->count() >= config('sentinel.quotas.organizations'), 409, 'Recipient organization quota reached.');
            $locked->members()->updateExistingPivot($locked->owner_id, ['role' => 'admin']);
            $locked->members()->updateExistingPivot($member->id, ['role' => 'owner']);
            $locked->owner_id = $member->id;
            $locked->save();
        }, 3);

        return new OrganizationResource($organization->refresh());
    }
}
