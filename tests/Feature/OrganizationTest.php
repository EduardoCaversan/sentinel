<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationTestCase;

class OrganizationTest extends OrganizationTestCase
{
    public function test_create_list_update_and_personal_migration_compatibility(): void
    {
        $created = $this->postJson('/api/v2/organizations', ['name' => 'Operations', 'owner_id' => 999])->assertCreated();
        $this->assertDatabaseHas('organizations', ['id' => $created->json('data.id'), 'owner_id' => $this->owner->id]);
        $this->getJson('/api/v2/organizations')->assertOk()->assertJsonPath('meta.total', 3);
        $this->patchJson($this->api(), ['name' => 'New Platform', 'retention_days' => 7])->assertOk()->assertJsonPath('data.name', 'New Platform');
        $personal = $this->owner->personalOrganization();
        $legacy = Monitor::factory()->for($this->owner)->create();
        $this->assertSame($personal->id, $legacy->organization_id);
        $this->getJson('/api/v1/monitors/'.$legacy->id)->assertOk();
        $shared = $this->monitor();
        $this->getJson('/api/v1/monitors/'.$shared->id)->assertNotFound();
    }

    #[DataProvider('roles')]
    public function test_rbac(string $role, string $method, string $path, array $body, int $expected): void
    {
        Sanctum::actingAs($this->member($role));
        $this->json($method, $this->api($path), $body)->assertStatus($expected);
    }

    public static function roles(): array
    {
        $cases = [];
        foreach (['owner', 'admin', 'member', 'viewer'] as $role) {
            $admin = in_array($role, ['owner', 'admin'], true);
            $writer = $role !== 'viewer';
            foreach ([
                ['GET', '/monitors', [], 200],
                ['POST', '/monitors', ['name' => 'API', 'url' => 'https://example.com'], $writer ? 201 : 403],
                ['PATCH', '', ['name' => 'Updated'], $admin ? 200 : 403],
                ['GET', '/api-keys', [], $admin ? 200 : 403],
                ['GET', '/members', [], $admin ? 200 : 403],
                ['GET', '/notification-channels', [], $admin ? 200 : 403],
                ['POST', '/status-pages', ['name' => 'Public'], $admin ? 422 : 403],
                ['GET', '/maintenance', [], 200],
                ['GET', '/incidents', [], 200],
            ] as [$method, $path, $body, $status]) {
                $cases[$role.' '.$method.' '.$path] = [$role, $method, $path, $body, $status];
            }
        }

        return $cases;
    }

    public function test_invitation_acceptance_is_bound_to_email_and_single_use(): void
    {
        $invitee = User::factory()->create();
        $invited = $this->postJson($this->api('/invitations'), ['email' => strtoupper($invitee->email), 'role' => 'member'])->assertCreated();
        $token = $invited->json('data.token');
        $this->assertNotSame($token, OrganizationInvitation::first()->token_hash);
        $this->getJson($this->api('/invitations'))->assertOk()->assertJsonMissingPath('data.0.token')->assertJsonMissingPath('data.0.token_hash');
        $this->postJson('/api/v2/invitations/accept', ['token' => $token])->assertForbidden();
        Sanctum::actingAs($invitee);
        $this->postJson('/api/v2/invitations/accept', ['token' => $token])->assertOk();
        $this->postJson('/api/v2/invitations/accept', ['token' => $token])->assertNotFound();
        $this->getJson($this->api('/monitors'))->assertOk();
    }

    public function test_expired_invitation_and_admin_escalation_are_blocked(): void
    {
        $invitee = User::factory()->create();
        $token = $this->postJson($this->api('/invitations'), ['email' => $invitee->email, 'role' => 'viewer'])->json('data.token');
        OrganizationInvitation::query()->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($invitee);
        $this->postJson('/api/v2/invitations/accept', ['token' => $token])->assertStatus(410);
        Sanctum::actingAs($this->member('admin'));
        $this->postJson($this->api('/invitations'), ['email' => 'peer@example.com', 'role' => 'admin'])->assertForbidden();
        $this->patchJson($this->api('/members/'.$this->owner->id), ['role' => 'viewer'])->assertStatus(409);
    }

    public function test_owner_transfer_removal_and_leave(): void
    {
        $admin = $this->member('admin');
        $this->postJson($this->api('/leave'))->assertStatus(409);
        $this->postJson($this->api('/transfer-ownership'), ['user_id' => $admin->id])->assertOk()->assertJsonPath('data.owner_id', $admin->id);
        $this->postJson($this->api('/transfer-ownership'), ['user_id' => $this->owner->id])->assertForbidden();
        $this->postJson($this->api('/leave'))->assertNoContent();
        $this->getJson($this->api())->assertNotFound();
        Sanctum::actingAs($admin);
        $member = $this->member('member');
        $this->deleteJson($this->api('/members/'.$member->id))->assertNoContent();
        Sanctum::actingAs($member);
        $this->getJson($this->api('/monitors'))->assertNotFound();
    }

    public function test_cross_organization_and_nested_ids_are_not_accessible(): void
    {
        $other = Monitor::factory()->create();
        foreach (['', '/checks', '/incidents', '/analytics'] as $suffix) {
            $this->getJson($this->api('/monitors/'.$other->id.$suffix))->assertNotFound();
        }
        $this->patchJson($this->api('/monitors/'.$other->id), ['name' => 'stolen'])->assertNotFound();
        $this->deleteJson($this->api('/monitors/'.$other->id))->assertNotFound();
        $this->postJson($this->api('/monitors/'.$other->id.'/check'))->assertNotFound();
        $this->getJson('/api/v2/organizations/'.$other->organization_id.'/monitors')->assertNotFound();
        $incident = $other->incidents()->create(['started_at' => now(), 'failure_count' => 3]);
        $this->postJson($this->api('/incidents/'.$incident->id.'/acknowledge'))->assertNotFound();
        $this->getJson($this->api('/incidents/'.$incident->id.'/timeline'))->assertNotFound();
    }

    public function test_quotas_count_invitations_and_are_not_bypassed_by_v1(): void
    {
        config(['sentinel.quotas.members' => 2, 'sentinel.quotas.monitors' => 1, 'sentinel.quotas.organizations' => 1]);
        $this->postJson($this->api('/invitations'), ['email' => 'one@example.com', 'role' => 'member'])->assertCreated();
        $this->postJson($this->api('/invitations'), ['email' => 'two@example.com', 'role' => 'member'])->assertStatus(409);
        $this->postJson('/api/v2/organizations', ['name' => 'Over quota'])->assertStatus(409);
        $this->postJson('/api/v1/monitors', ['name' => 'First', 'url' => 'https://example.com'])->assertCreated();
        $this->postJson('/api/v1/monitors', ['name' => 'Second', 'url' => 'https://example.com'])->assertStatus(409);
        $this->patchJson($this->api(), ['retention_days' => 10000])->assertUnprocessable();
    }
}
