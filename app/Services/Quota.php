<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;

class Quota
{
    public function create(Organization $organization, string $resource, Closure $create): mixed
    {
        return DB::transaction(function () use ($organization, $resource, $create) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $table = ['monitors' => 'monitors', 'channels' => 'notification_channels', 'api_keys' => 'organization_api_keys', 'status_pages' => 'status_pages', 'maintenance' => 'maintenance_windows'][$resource];
            $query = DB::table($table)->where('organization_id', $organization->id);
            if ($resource === 'api_keys') {
                $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            }
            if ($resource === 'maintenance') {
                $query->where('end_at', '>', now());
            }
            abort_if($query->count() >= config('sentinel.quotas.'.$resource), 409, 'Organization quota reached: '.$resource.'.');

            return $create();
        }, 3);
    }
}
