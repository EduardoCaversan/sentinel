<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use Illuminate\Support\Str;

class NotificationOutbox
{
    public function record(Monitor $monitor, MonitorCheck $check, ?Incident $incident, array $events): void
    {
        if ($events === []) {
            return;
        }
        $channels = NotificationChannel::where('organization_id', $monitor->organization_id)->where('is_active', true)->get();
        foreach ($channels as $channel) {
            foreach (array_intersect($events, $channel->events) as $event) {
                NotificationDelivery::create([
                    'organization_id' => $monitor->organization_id,
                    'notification_channel_id' => $channel->id,
                    'monitor_id' => $monitor->id,
                    'public_id' => (string) Str::uuid(),
                    'event' => $event,
                    'execution_id' => $check->execution_id,
                    'payload' => ['event' => $event, 'occurred_at' => $check->checked_at->toISOString(), 'monitor' => ['id' => $monitor->id, 'name' => $monitor->name, 'status' => $monitor->status->value], 'incident_id' => $incident?->id],
                    'next_attempt_at' => now(),
                ]);
            }
        }
    }
}
