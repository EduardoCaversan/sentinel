<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Services\WebhookSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(WebhookSender $sender): void
    {
        $lock = Cache::lock('delivery:'.$this->deliveryId, 90);
        if (! $lock->get()) {
            return;
        }
        try {
            $delivery = NotificationDelivery::with('channel')->find($this->deliveryId);
            if (! $delivery || ! in_array($delivery->status, ['pending', 'retrying'], true) || $delivery->next_attempt_at->isFuture()) {
                return;
            }
            if (! $delivery->channel || ! $delivery->channel->is_active) {
                $delivery->update(['status' => 'cancelled']);

                return;
            }
            $result = $sender->send($delivery->channel, $delivery);
            DB::transaction(function () use ($delivery, $result): void {
                $locked = NotificationDelivery::whereKey($delivery->id)->lockForUpdate()->first();
                if (! $locked || ! in_array($locked->status, ['pending', 'retrying'], true)) {
                    return;
                }
                $locked->attempts++;
                $locked->deliveryAttempts()->create(['attempt' => $locked->attempts, 'http_status' => $result['http_status'], 'error_type' => $result['error_type'], 'duration_ms' => $result['duration_ms'], 'attempted_at' => now()]);
                if ($result['error_type'] === null) {
                    $locked->status = 'delivered';
                    $locked->delivered_at = now();
                } elseif ($result['retryable'] && $locked->attempts < 5) {
                    $locked->status = 'retrying';
                    $locked->next_attempt_at = now()->addSeconds([30, 120, 600, 1800][$locked->attempts - 1]);
                } else {
                    $locked->status = 'failed';
                }
                $locked->save();
                Log::info('notification.attempt', ['organization_id' => $locked->organization_id, 'monitor_id' => $locked->monitor_id, 'execution_id' => $locked->execution_id, 'delivery_id' => $locked->id, 'status' => $locked->status, 'attempt' => $locked->attempts, 'http_status' => $result['http_status'], 'error_type' => $result['error_type']]);
            });
        } finally {
            $lock->release();
        }
    }
}
