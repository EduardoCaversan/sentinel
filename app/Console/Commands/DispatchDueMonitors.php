<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckMonitorJob;
use App\Models\Monitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchDueMonitors extends Command
{
    protected $signature = 'monitors:dispatch';

    protected $description = 'Queue active monitors whose next check is due';

    public function handle(): int
    {
        Cache::put('sentinel:scheduler:last_tick', now()->toISOString(), 3600);
        Monitor::where('is_active', true)
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->select('id')->chunkById(200, function ($monitors): void {
                foreach ($monitors as $monitor) {
                    CheckMonitorJob::dispatch($monitor->id);
                }
            });

        return self::SUCCESS;
    }
}
