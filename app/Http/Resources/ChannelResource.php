<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationChannel */
class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'events' => $this->events, 'is_active' => $this->is_active, 'has_signing_secret' => $this->signing_secret !== null, 'created_at' => $this->created_at->toISOString()];
    }
}
