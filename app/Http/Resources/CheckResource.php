<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MonitorCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonitorCheck */
class CheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'monitor_id' => $this->monitor_id, 'status' => $this->status->value, 'http_status_code' => $this->http_status_code, 'response_time_ms' => $this->response_time_ms, 'error_type' => $this->error_type, 'error_message' => $this->error_message, 'in_maintenance' => $this->in_maintenance, 'checked_at' => $this->checked_at->toISOString(), 'created_at' => $this->created_at->toISOString()];
    }
}
