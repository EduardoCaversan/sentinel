<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\IncidentStatus;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Services\RecordCheck;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\OrganizationTestCase;

class MaintenanceIncidentTest extends OrganizationTestCase
{
    private function record(Monitor $monitor, bool $success): void
    {
        app(RecordCheck::class)->store($monitor->refresh(), (string) Str::uuid(), ['status' => $success ? CheckStatus::Success : CheckStatus::Failure, 'http_status_code' => $success ? 200 : 503, 'response_time_ms' => 15, 'checked_at' => now()]);
        $this->travel(60)->seconds();
    }

    public function test_maintenance_keeps_checks_but_resets_streaks_and_suppresses_incidents(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['failure_threshold' => 2]);
        $this->record($monitor, false);
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'Deploy', 'start_at' => now(), 'end_at' => now()->addMinutes(2)]);
        $window->monitors()->attach($monitor);
        $this->record($monitor, false);
        $this->record($monitor, false);
        $this->assertSame(0, $monitor->incidents()->count());
        $this->assertSame(2, $monitor->checks()->where('in_maintenance', true)->count());
        $this->record($monitor, false);
        $this->assertSame(0, $monitor->incidents()->count());
        $this->record($monitor, false);
        $this->assertSame(1, $monitor->incidents()->count());
        $this->getJson($this->api("/monitors/{$monitor->id}/checks"))->assertOk()->assertJsonPath('data.0.in_maintenance', false);
    }

    public function test_existing_incident_is_not_resolved_during_maintenance(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['failure_threshold' => 1, 'recovery_threshold' => 2]);
        $this->record($monitor, false);
        $this->record($monitor, true);
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'Deploy', 'start_at' => now(), 'end_at' => now()->addMinute()]);
        $window->monitors()->attach($monitor);
        $this->record($monitor, true);
        $this->record($monitor, true);
        $this->assertSame(IncidentStatus::Open, $monitor->incidents()->first()->status);
        $this->record($monitor, true);
        $this->assertSame(IncidentStatus::Resolved, $monitor->incidents()->first()->status);
    }

    public function test_window_crossed_without_a_check_still_resets_failure_streak(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['failure_threshold' => 2]);
        $this->record($monitor, false);
        $window = MaintenanceWindow::create(['organization_id' => $this->organization->id, 'description' => 'Deploy', 'start_at' => now(), 'end_at' => now()->addMinute()]);
        $window->monitors()->attach($monitor);
        $this->travel(2)->minutes();
        $this->record($monitor, false);
        $this->assertSame(0, $monitor->incidents()->count());
    }

    public function test_maintenance_api_validates_tenants_dates_and_immutable_history(): void
    {
        $monitor = $this->monitor();
        $other = Monitor::factory()->create();
        $payload = ['description' => 'Database upgrade', 'start_at' => now()->addHour()->toISOString(), 'end_at' => now()->addHours(2)->toISOString(), 'monitor_ids' => [$monitor->id]];
        $id = $this->postJson($this->api('/maintenance'), $payload)->assertCreated()->json('data.id');
        $this->getJson($this->api('/maintenance'))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->api('/maintenance'), [...$payload, 'monitor_ids' => [$other->id]])->assertUnprocessable();
        $this->postJson($this->api('/maintenance'), [...$payload, 'end_at' => now()->toISOString()])->assertUnprocessable();
        $this->deleteJson($this->api("/maintenance/$id"))->assertNoContent();
        $id = $this->postJson($this->api('/maintenance'), [...$payload, 'start_at' => now()->toISOString()])->assertCreated()->json('data.id');
        $this->deleteJson($this->api("/maintenance/$id"))->assertConflict();
        config(['sentinel.quotas.maintenance' => 1]);
        $this->postJson($this->api('/maintenance'), $payload)->assertConflict();
    }

    public function test_incident_acknowledgement_notes_and_timeline_preserve_automatic_recovery(): void
    {
        $this->freezeTime();
        $monitor = $this->monitor(['failure_threshold' => 2, 'recovery_threshold' => 2]);
        $this->record($monitor, false);
        $first = $monitor->checks()->first()->checked_at;
        $this->record($monitor, false);
        $incident = $monitor->incidents()->first();
        $this->assertTrue($first->equalTo($incident->first_failed_at));
        $path = $this->api("/incidents/{$incident->id}");
        $this->postJson("$path/acknowledge")->assertOk()->assertJsonPath('data.acknowledged_by', $this->owner->id);
        $this->postJson("$path/acknowledge")->assertOk();
        $this->assertSame(1, $incident->events()->where('type', 'acknowledged')->count());
        $this->postJson("$path/notes", ['message' => 'Investigating upstream timeouts.'])->assertCreated();
        $this->postJson("$path/notes", ['message' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->record($monitor, true);
        $this->record($monitor, false);
        $this->record($monitor, true);
        $this->record($monitor, true);
        $data = $this->getJson("$path/timeline")->assertOk()->json('data');
        $types = array_column($data, 'type');
        foreach (['incident.opened', 'acknowledged', 'note', 'recovery_started', 'recovery_interrupted', 'incident.resolved', 'check'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.duration_seconds', 240);
        $this->postJson("$path/acknowledge")->assertConflict();
        Sanctum::actingAs($this->member('viewer'));
        $this->postJson("$path/notes", ['message' => 'Unauthorized'])->assertForbidden();
    }
}
