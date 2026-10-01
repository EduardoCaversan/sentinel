<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;

class MonitorCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => CheckStatus::class, 'checked_at' => 'datetime'];
    }
}
