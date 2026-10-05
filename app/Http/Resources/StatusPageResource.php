<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StatusPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StatusPage */
class StatusPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'name' => $this->name, 'description' => $this->description, 'is_published' => $this->is_published, 'public_url' => '/status/'.$this->slug, 'components' => $this->monitors->map(fn ($monitor) => ['monitor_id' => $monitor->id, 'name' => $monitor->pivot->getAttribute('name')])->all()];
    }
}
