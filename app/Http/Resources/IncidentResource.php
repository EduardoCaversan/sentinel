<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Incident */
class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $extra = ['acknowledged_by' => $this->acknowledged_by, 'acknowledged_by_key' => $this->acknowledged_by_key, 'acknowledged_at' => $this->acknowledged_at?->toISOString(), 'duration_seconds' => $this->resource->durationSeconds()];

        return [...$extra, 'first_failed_at' => $this->first_failed_at?->toISOString(), 'id' => $this->id, 'monitor_id' => $this->monitor_id, 'status' => $this->status->value, 'severity' => $this->severity, 'started_at' => $this->started_at->toISOString(), 'resolved_at' => $this->resolved_at?->toISOString(), 'failure_count' => $this->failure_count, 'recovery_count' => $this->recovery_count, 'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString()];
    }
}
