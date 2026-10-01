<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\HttpProbe;
use App\Services\RecordCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CheckMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public string $executionId;

    public function __construct(public int $monitorId)
    {
        $this->executionId = (string) Str::uuid();
        $this->onQueue('checks');
    }

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public function handle(HttpProbe $probe, RecordCheck $recorder): void
    {
        $lock = Cache::lock('monitor:'.$this->monitorId, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $monitor = Monitor::find($this->monitorId);
            if (! $monitor || ! $monitor->is_active || MonitorCheck::where('execution_id', $this->executionId)->exists()) {
                return;
            }
            $recorder->store($monitor, $this->executionId, $probe->run($monitor));
        } finally {
            $lock->release();
        }
    }
}
