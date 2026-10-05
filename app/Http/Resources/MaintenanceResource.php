<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MaintenanceWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenanceWindow */
class MaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'description' => $this->description, 'start_at' => $this->start_at->toISOString(), 'end_at' => $this->end_at->toISOString(), 'status' => $this->resource->state(), 'monitor_ids' => $this->monitors->modelKeys()];
    }
}
