<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'owner_id' => $this->owner_id, 'is_personal' => $this->personal_user_id !== null, 'retention_days' => $this->retention_days, 'created_at' => $this->created_at->toISOString()];
    }
}
