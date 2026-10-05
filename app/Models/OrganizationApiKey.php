<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property list<string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class OrganizationApiKey extends Model
{
    public const SCOPES = ['monitors:read', 'monitors:write', 'incidents:read', 'incidents:write', 'maintenance:read', 'maintenance:write', 'analytics:read', 'status-pages:read', 'status-pages:write', 'notifications:read', 'notifications:write'];

    protected $guarded = ['id'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
