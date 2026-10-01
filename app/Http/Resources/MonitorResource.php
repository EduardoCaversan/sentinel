<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'url' => $this->url, 'method' => $this->method,
            'expected_status_code' => $this->expected_status_code, 'interval_seconds' => $this->interval_seconds,
            'timeout_seconds' => $this->timeout_seconds, 'failure_threshold' => $this->failure_threshold,
            'recovery_threshold' => $this->recovery_threshold, 'status' => $this->status->value,
            'is_active' => $this->is_active, 'consecutive_failures' => $this->consecutive_failures,
            'consecutive_successes' => $this->consecutive_successes,
            'last_checked_at' => $this->last_checked_at?->toISOString(), 'next_check_at' => $this->next_check_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
