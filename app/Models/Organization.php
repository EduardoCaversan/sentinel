<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['name', 'description', 'retention_days'];

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Monitor, $this> */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function roleFor(User $user): ?Role
    {
        $member = $this->members()->whereKey($user->id)->first();
        if (! $member) {
            return null;
        }

        return $this->owner_id === $user->id ? Role::Owner : Role::from($member->pivot->getAttribute('role'));
    }
}
