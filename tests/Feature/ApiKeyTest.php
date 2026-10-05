<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\OrganizationApiKey;
use Tests\OrganizationTestCase;

class ApiKeyTest extends OrganizationTestCase
{
    private function issue(array $scopes): array
    {
        return $this->postJson($this->api('/api-keys'), ['name' => 'CI automation', 'scopes' => $scopes])->assertCreated()->json('data');
    }

    public function test_secret_is_one_time_hashed_and_scoped(): void
    {
        $key = $this->issue(['monitors:read']);
        $this->assertSame(hash('sha256', $key['secret']), OrganizationApiKey::find($key['id'])->secret_hash);
        $this->getJson($this->api('/api-keys'))->assertJsonMissingPath('data.0.secret')->assertJsonMissingPath('data.0.secret_hash');
        $monitor = $this->monitor();
        $this->withToken($key['secret'])->getJson($this->api('/monitors/'.$monitor->id))->assertOk();
        $this->assertNotNull(OrganizationApiKey::find($key['id'])->last_used_at);
        $this->postJson($this->api('/monitors'), ['name' => 'Denied', 'url' => 'https://example.com'])->assertForbidden();
        $this->getJson($this->api('/incidents'))->assertForbidden();
        $this->postJson('/api/v2/organizations', ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v2/auth/me')->assertForbidden();
        $other = Monitor::factory()->create();
        $this->getJson('/api/v2/organizations/'.$other->organization_id.'/monitors')->assertNotFound();
    }

    public function test_revocation_expiry_and_forged_keys(): void
    {
        $key = $this->issue(['monitors:read']);
        $this->deleteJson($this->api('/api-keys/'.$key['id']))->assertNoContent();
        $this->withToken($key['secret'])->getJson($this->api('/monitors'))->assertUnauthorized();
        OrganizationApiKey::whereKey($key['id'])->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->getJson($this->api('/monitors'))->assertUnauthorized();
        $this->withToken('snl_'.str_repeat('f', 64))->getJson($this->api('/monitors'))->assertUnauthorized();
    }

    public function test_write_scope_does_not_imply_read_or_key_administration(): void
    {
        $key = $this->issue(['monitors:write']);
        $this->withToken($key['secret'])->postJson($this->api('/monitors'), ['name' => 'Automation', 'url' => 'https://example.com'])->assertCreated();
        $this->getJson($this->api('/monitors'))->assertForbidden();
        $this->postJson($this->api('/api-keys'), ['name' => 'Escalate', 'scopes' => ['incidents:write']])->assertForbidden();
    }

    public function test_invalid_scopes_expiry_and_quota(): void
    {
        $this->postJson($this->api('/api-keys'), ['name' => 'Bad', 'scopes' => ['*']])->assertUnprocessable();
        $this->postJson($this->api('/api-keys'), ['name' => 'Bad', 'scopes' => ['monitors:read'], 'expires_at' => now()->subDay()->toISOString()])->assertUnprocessable();
        config(['sentinel.quotas.api_keys' => 1]);
        $key = $this->issue(['monitors:read']);
        $this->postJson($this->api('/api-keys'), ['name' => 'Over quota', 'scopes' => ['monitors:read']])->assertStatus(409);
        $this->deleteJson($this->api('/api-keys/'.$key['id']))->assertNoContent();
        $this->issue(['monitors:read']);
    }
}
