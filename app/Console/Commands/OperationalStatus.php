<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class OperationalStatus extends Command
{
    protected $signature = 'sentinel:operations';

    protected $description = 'Print private operational metrics as JSON (host/operator access only)';

    public function handle(): int
    {
        $this->line(json_encode([
            'scheduler_last_tick' => Cache::get('sentinel:scheduler:last_tick'),
            'queue_backlog' => ['checks' => Queue::connection('redis')->size('checks'), 'notifications' => Queue::connection('redis')->size('notifications')],
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'checks_24h' => MonitorCheck::where('checked_at', '>=', now()->subDay())->count(),
            'failures_24h' => MonitorCheck::where('checked_at', '>=', now()->subDay())->where('status', '!=', 'success')->count(),
            'notification_failures' => NotificationDelivery::where('status', 'failed')->count(),
            'notification_pending' => NotificationDelivery::whereIn('status', ['pending', 'retrying'])->count(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
