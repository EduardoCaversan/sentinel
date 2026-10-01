<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $guarded = ['id', 'open_slot'];

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class, 'started_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
