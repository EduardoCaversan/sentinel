<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property CheckStatus $status
 * @property Carbon $checked_at
 * @property bool $in_maintenance
 */
class MonitorCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    protected function casts(): array
    {
        return ['status' => CheckStatus::class, 'checked_at' => 'datetime', 'in_maintenance' => 'boolean'];
    }
}
