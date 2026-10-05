<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function allows(string $ability): bool
    {
        if ($ability === 'ownership:transfer') {
            return $this === self::Owner;
        }
        if (in_array($this, [self::Owner, self::Admin], true)) {
            return true;
        }
        $read = ['organization:read', 'monitors:read', 'incidents:read', 'maintenance:read', 'analytics:read', 'status-pages:read'];

        return in_array($ability, $read, true) || ($this === self::Member && in_array($ability, ['monitors:write', 'incidents:write', 'maintenance:write'], true));
    }
}
