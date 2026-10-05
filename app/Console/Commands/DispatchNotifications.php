<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Dispatch pending durable notification deliveries';

    public function handle(): int
    {
        NotificationDelivery::whereIn('status', ['pending', 'retrying'])->where('next_attempt_at', '<=', now())->select('id')
            ->chunkById(200, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    DeliverNotification::dispatch($delivery->id);
                }
            });

        return self::SUCCESS;
    }
}
