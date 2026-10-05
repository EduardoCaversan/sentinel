<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\OrganizationAccess;
use App\Http\Requests\InvitationRequest;
use App\Http\Requests\PageRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(PageRequest $request): AnonymousResourceCollection
    {
        $members = OrganizationAccess::organization($request)->members()->orderBy('users.id')->paginate($request->pageSize());
        $members->through(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'role' => $member->pivot->getAttribute('role')]);

        return JsonResource::collection($members);
    }

    public function invite(InvitationRequest $request): JsonResponse
    {
        $organization = OrganizationAccess::organization($request);
        $secret = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($request, $organization, $secret): OrganizationInvitation {
            $locked = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $this->canAssign($locked, $request, $request->validated('role'));
            abort_if($locked->members()->where('email', $request->validated('email'))->exists(), 409, 'User is already a member.');
            OrganizationInvitation::where('organization_id', $locked->id)->where('expires_at', '<=', now())->delete();
            abort_if(OrganizationInvitation::where('organization_id', $locked->id)->where('email', $request->validated('email'))->exists(), 409, 'A pending invitation already exists.');
            $this->checkMemberQuota($locked);

            return OrganizationInvitation::create([...$request->validated(), 'organization_id' => $locked->id, 'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addDays(7)]);
        });

        return response()->json(['data' => [...$invitation->toArray(), 'token' => $secret]], 201)->header('Cache-Control', 'no-store');
    }

    public function invitations(PageRequest $request): AnonymousResourceCollection
    {
        return JsonResource::collection(OrganizationInvitation::where('organization_id', OrganizationAccess::organization($request)->id)->latest('id')->paginate($request->pageSize()));
    }

    public function cancel(Request $request, OrganizationInvitation $invitation): Response
    {
        $this->canAssign(OrganizationAccess::organization($request), $request, 'member', $invitation->role);
        $invitation->delete();

        return response()->noContent();
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']]);
        $candidate = OrganizationInvitation::where('token_hash', hash('sha256', $data['token']))->firstOrFail();
        DB::transaction(function () use ($candidate, $request, $data): void {
            $organization = Organization::whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = OrganizationInvitation::whereKey($candidate->id)->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->firstOrFail();
            abort_if($invitation->expires_at->isPast(), 410, 'Invitation expired.');
            abort_unless(hash_equals($invitation->email, strtolower($request->user()->email)), 403, 'Invitation belongs to another email.');
            abort_if($organization->members()->whereKey($request->user()->id)->exists(), 409, 'Already a member.');
            abort_if($organization->members()->count() >= config('sentinel.quotas.members'), 409, 'Organization member quota reached.');
            $organization->members()->attach($request->user()->id, ['role' => $invitation->role]);
            $invitation->delete();
        }, 3);

        return response()->json(['data' => ['organization_id' => $candidate->organization_id, 'message' => 'Invitation accepted.']]);
    }

    public function update(Request $request, string $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member', 'viewer'])]]);
        DB::transaction(function () use ($request, $member, $data): void {
            $organization = Organization::whereKey(OrganizationAccess::organization($request)->id)->lockForUpdate()->firstOrFail();
            $target = $organization->members()->findOrFail($member);
            abort_if($target->id === $organization->owner_id, 409, 'Transfer ownership first.');
            $this->canAssign($organization, $request, $data['role'], $target->pivot->getAttribute('role'));
            $organization->members()->updateExistingPivot($target->id, ['role' => $data['role']]);
        });

        return response()->json(['data' => ['message' => 'Member role updated.']]);
    }

    public function destroy(Request $request, string $member): Response
    {
        $this->remove($request, (int) $member, false);

        return response()->noContent();
    }

    public function leave(Request $request): Response
    {
        $this->remove($request, $request->user()->id, true);

        return response()->noContent();
    }

    private function remove(Request $request, int $userId, bool $self): void
    {
        DB::transaction(function () use ($request, $userId, $self): void {
            $organization = Organization::whereKey(OrganizationAccess::organization($request)->id)->lockForUpdate()->firstOrFail();
            $target = $organization->members()->findOrFail($userId);
            abort_if($target->id === $organization->owner_id, 409, 'Transfer ownership before leaving or removing the owner.');
            if (! $self) {
                $this->canAssign($organization, $request, 'member', $target->pivot->getAttribute('role'));
            }
            $organization->members()->detach($target->id);
        });
    }

    private function canAssign(Organization $organization, Request $request, string $role, ?string $existing = null): void
    {
        $actor = $organization->roleFor($request->user());
        abort_unless($actor === Role::Owner || ($actor === Role::Admin && $role !== 'admin' && $existing !== 'admin'), 403, 'Only the owner may manage administrators.');
    }

    private function checkMemberQuota(Organization $organization): void
    {
        $reserved = OrganizationInvitation::where('organization_id', $organization->id)->where('expires_at', '>', now())->count();
        abort_if($organization->members()->count() + $reserved >= config('sentinel.quotas.members'), 409, 'Organization member quota reached (including invitations).');
    }
}
