<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Jobs\DeliverNotification;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\OrganizationApiKey;
use App\Models\User;
use App\Services\RecordCheck;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\OrganizationTestCase;

class V2SecurityRegressionTest extends OrganizationTestCase
{
    public function test_database_errors_are_sanitized_and_unique_conflicts_return_409(): void
    {
        Log::spy();
        $error = new UniqueConstraintViolationException('mysql', 'insert secret SQL', ['private-token'], new \PDOException('private-token'));
        Route::get('/api/v2/test-conflict', fn () => throw $error);
        $this->getJson('/api/v2/test-conflict')->assertConflict()->assertDontSee('private-token');
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn ($message, $context) => $message === 'database.operation_failed' && ! str_contains(json_encode($context), 'private-token'),
        );
    }

    public function test_key_creation_returns_nullable_metadata_and_bounds_expiry(): void
    {
        $payload = ['name' => 'Key', 'scopes' => ['monitors:read']];
        $this->postJson($this->api('/api-keys'), $payload)->assertCreated()->assertJsonStructure(['data' => ['last_used_at', 'expires_at', 'revoked_at']]);
        $this->postJson($this->api('/api-keys'), [...$payload, 'expires_at' => '2099-01-01T00:00:00Z'])->assertUnprocessable();
    }

    public function test_admin_cannot_cancel_an_owner_created_admin_invitation(): void
    {
        $id = $this->postJson($this->api('/invitations'), ['email' => 'admin@example.com', 'role' => 'admin'])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->member('admin'));
        $this->deleteJson($this->api("/invitations/$id"))->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->api("/invitations/$id"))->assertNoContent();
    }

    public function test_degraded_and_recovered_events_are_tenant_scoped_and_suppressed_in_maintenance(): void
    {
        $this->travelTo(now()->startOfSecond());
        $monitor = $this->monitor();
        $channel = NotificationChannel::create(['organization_id' => $this->organization->id, 'name' => 'Hook', 'type' => 'webhook', 'endpoint' => 'https://example.com/hook', 'events' => NotificationChannel::EVENTS, 'is_active' => true]);
        $foreign = User::factory()->create()->personalOrganization();
        NotificationChannel::create(['organization_id' => $foreign->id, 'name' => 'Foreign', 'type' => 'webhook', 'endpoint' => 'https://example.com/private', 'events' => NotificationChannel::EVENTS, 'is_active' => true]);
        foreach ([CheckStatus::Failure, CheckStatus::Success] as $status) {
            app(RecordCheck::class)->store($monitor->refresh(), (string) Str::uuid(), ['status' => $status, 'response_time_ms' => 10, 'checked_at' => now()]);
            $this->travel(1)->minutes();
        }
        $this->assertSame(['monitor.degraded', 'monitor.recovered'], NotificationDelivery::orderBy('id')->pluck('event')->all());
        $this->assertSame([$channel->id], NotificationDelivery::distinct()->pluck('notification_channel_id')->all());
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'Deploy', 'start_at' => now(), 'end_at' => now()->addHour()]);
        $window->monitors()->attach($monitor);
        app(RecordCheck::class)->store($monitor->refresh(), (string) Str::uuid(), ['status' => CheckStatus::Failure, 'response_time_ms' => 10, 'checked_at' => now()]);
        $this->assertSame(2, NotificationDelivery::count());
        $delivery = NotificationDelivery::first();
        Http::fake(fn () => throw new ConnectionException('https://example.com/secret-webhook-token'));
        app()->call([new DeliverNotification($delivery->id), 'handle']);
        $this->assertSame('retrying', $delivery->refresh()->status);
        $this->assertSame('transport', $delivery->deliveryAttempts()->first()->error_type);
        $this->getJson($this->api("/notification-deliveries/{$delivery->id}/attempts"))->assertOk()->assertDontSee('secret-webhook-token');
        $delivery->forceFill(['created_at' => now()->subDays(100)])->save();
        $this->artisan('sentinel:prune')->assertSuccessful();
        $this->assertDatabaseMissing('notification_attempts', ['notification_delivery_id' => $delivery->id]);
    }

    public function test_member_deletion_does_not_delete_organization_monitor_history(): void
    {
        $creator = $this->member('member');
        $monitor = $this->monitor(['user_id' => $creator->id]);
        app(RecordCheck::class)->store($monitor, (string) Str::uuid(), ['status' => CheckStatus::Success, 'response_time_ms' => 10, 'checked_at' => now()]);
        $creator->delete();
        $this->assertNull($monitor->refresh()->user_id);
        $this->assertSame(1, $monitor->checks()->count());
        $this->assertSame($this->organization->id, $monitor->organization_id);
    }

    public function test_mass_assignment_cannot_move_monitor_or_publish_foreign_components(): void
    {
        $foreign = User::factory()->create()->personalOrganization();
        $monitor = $this->monitor();
        $this->patchJson($this->api("/monitors/{$monitor->id}"), ['organization_id' => $foreign->id, 'user_id' => $foreign->owner_id, 'status' => 'healthy', 'configuration_version' => 999])->assertOk()->assertJsonPath('data.organization_id', $this->organization->id)->assertJsonPath('data.status', 'unknown');
        $this->assertSame(1, $monitor->refresh()->configuration_version);
    }

    public function test_manual_rate_limit_is_shared_across_v1_v2_and_api_keys(): void
    {
        $personal = $this->owner->personalOrganization();
        $monitor = $personal->monitors()->create(['name' => 'API', 'url' => 'https://example.com'])->refresh();
        for ($i = 0; $i < 6; $i++) {
            $this->postJson("/api/v1/monitors/{$monitor->id}/check")->assertAccepted();
        }
        $this->postJson("/api/v2/organizations/{$personal->id}/monitors/{$monitor->id}/check")->assertTooManyRequests()->assertHeader('Retry-After');
        $secret = 'snl_'.bin2hex(random_bytes(32));
        OrganizationApiKey::create(['organization_id' => $personal->id, 'name' => 'Automation', 'secret_hash' => hash('sha256', $secret), 'scopes' => ['monitors:write']]);
        $this->withToken($secret)->postJson("/api/v2/organizations/{$personal->id}/monitors/{$monitor->id}/check")->assertTooManyRequests();
        $this->assertSame(0, $monitor->checks()->count());
    }
}
