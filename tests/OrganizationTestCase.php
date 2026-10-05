<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

abstract class OrganizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn(['93.184.215.14']);
        $this->owner = User::factory()->create();
        $this->organization = new Organization(['name' => 'Platform', 'retention_days' => 30]);
        $this->organization->owner_id = $this->owner->id;
        $this->organization->save();
        $this->organization->members()->attach($this->owner->id, ['role' => 'owner']);
        Sanctum::actingAs($this->owner);
    }

    protected function api(string $path = ''): string
    {
        return '/api/v2/organizations/'.$this->organization->id.$path;
    }

    protected function monitor(array $attributes = []): Monitor
    {
        return Monitor::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->owner->id, ...$attributes])->refresh();
    }

    protected function member(string $role): User
    {
        if ($role === 'owner') {
            return $this->owner;
        }
        $user = User::factory()->create();
        $this->organization->members()->attach($user->id, ['role' => $role]);

        return $user;
    }
}
