<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_opt_in_realistic_and_idempotent(): void
    {
        config(['sentinel.demo_email' => null, 'sentinel.demo_password' => null]);
        $this->seed();
        $this->assertSame(0, User::count());
        config(['sentinel.demo_email' => 'demo@example.com', 'sentinel.demo_password' => 'ExampleOnly123!']);
        $this->seed();
        $this->seed();
        $this->assertSame(1, User::count());
        $this->assertSame(3, Monitor::count());
        $this->assertSame(16, MonitorCheck::count());
        $this->assertSame(1, Incident::where('status', 'resolved')->count());
    }
}
