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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public string $executionId;

    public int $enqueuedAt = 0;

    public function __construct(public int $monitorId)
    {
        $this->executionId = (string) Str::uuid();
        $this->enqueuedAt = now()->timestamp;
        $this->onQueue('checks');
    }

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public function handle(HttpProbe $probe, RecordCheck $recorder): void
    {
        // Pre-V2 payloads are rescheduled by the next dispatch; stale jobs cannot
        // bypass deduplication after bounded receipt/history retention.
        if ($this->enqueuedAt < now()->timestamp - 86400) {
            return;
        }
        $lock = Cache::lock('monitor:'.$this->monitorId, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $monitor = Monitor::find($this->monitorId);
            if (! $monitor || ! $monitor->is_active || DB::table('check_executions')->where('execution_id', $this->executionId)->exists() || MonitorCheck::where('execution_id', $this->executionId)->exists()) {
                return;
            }
            $recorder->store($monitor, $this->executionId, $probe->run($monitor));
        } finally {
            $lock->release();
        }
    }
}
