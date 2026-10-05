<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['period' => ['sometimes', Rule::in(['24h', '7d', '30d', 'custom'])], 'start_at' => ['required_if:period,custom', 'date', 'required_with:end_at'], 'end_at' => ['required_if:period,custom', 'date', 'after:start_at', 'before_or_equal:'.now()->toISOString(), 'required_with:start_at']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isEmpty() && $this->filled('start_at') && CarbonImmutable::parse($this->input('start_at'))->diffInSeconds(CarbonImmutable::parse($this->input('end_at'))) > 31 * 86400) {
                $validator->errors()->add('end_at', 'Custom ranges cannot exceed 31 days.');
            }
        }];
    }

    public function range(): array
    {
        if ($this->filled('start_at')) {
            return [CarbonImmutable::parse($this->validated('start_at'))->utc(), CarbonImmutable::parse($this->validated('end_at'))->utc()];
        }
        $end = CarbonImmutable::now();

        return [$end->subDays(match ($this->input('period', '24h')) {
            '7d' => 7, '30d' => 30, default => 1
        }), $end];
    }
}
