<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'monitor_id' => $this->monitor_id, 'status' => $this->status->value, 'severity' => $this->severity, 'started_at' => $this->started_at->toISOString(), 'resolved_at' => $this->resolved_at?->toISOString(), 'failure_count' => $this->failure_count, 'recovery_count' => $this->recovery_count, 'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString()];
    }
}
