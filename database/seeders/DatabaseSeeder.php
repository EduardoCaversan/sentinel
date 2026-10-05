<?php

namespace Database\Seeders;

use App\Enums\CheckStatus;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\RecordCheck;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('sentinel.demo_email');
        $password = config('sentinel.demo_password');
        if (! $email || ! is_string($password) || strlen($password) < 12) {
            $this->command?->warn('Set DEMO_EMAIL and DEMO_PASSWORD (at least 12 characters) to seed demo data.');

            return;
        }
        $user = User::firstOrCreate(['email' => strtolower($email)], ['name' => 'Sentinel Demo', 'password' => $password]);
        $organization = $user->personalOrganization();
        $components = [];
        foreach (['Payment API', 'Authentication API', 'Checkout API'] as $index => $name) {
            $monitor = $organization->monitors()->firstOrCreate(['name' => $name], ['url' => 'https://example.com', 'interval_seconds' => max(300, config('sentinel.min_interval_seconds'))]);
            $monitor->refresh();
            $components[$monitor->id] = ['name' => $name, 'position' => $index];
            if ($monitor->checks()->exists()) {
                continue;
            }
            $sequence = $index === 0 ? [true, true, false, false, false, false, true, true] : [true, true, true, true];
            foreach ($sequence as $step => $success) {
                app(RecordCheck::class)->store($monitor, (string) Str::uuid(), [
                    'status' => $success ? CheckStatus::Success : CheckStatus::Failure,
                    'http_status_code' => $success ? 200 : 503,
                    'response_time_ms' => 75 + $step * 13,
                    'error_type' => $success ? null : 'unexpected_status',
                    'error_message' => $success ? null : 'The endpoint returned an unexpected HTTP status.',
                    'checked_at' => now()->subMinutes((count($sequence) - $step) * 5),
                ]);
            }
        }
        $page = StatusPage::firstOrCreate(
            ['organization_id' => $organization->id, 'slug' => 'sentinel-demo-'.$organization->id],
            ['name' => 'Sentinel Demo Status', 'description' => 'Demonstration services; all probes target example.com.', 'is_published' => true],
        );
        if ($page->wasRecentlyCreated) {
            $page->monitors()->sync($components);
        }
    }
}
