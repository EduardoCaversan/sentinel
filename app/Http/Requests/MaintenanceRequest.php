<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\OrganizationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:1000'],
            'start_at' => ['required', 'date', 'after_or_equal:'.now()->subMinute()->toISOString()],
            'end_at' => ['required', 'date', 'after:start_at', 'before_or_equal:'.now()->addDays(90)->toISOString()],
            'monitor_ids' => ['required', 'array', 'min:1', 'max:50'],
            'monitor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('monitors', 'id')->where('organization_id', OrganizationAccess::organization($this)->id)],
        ];
    }
}
