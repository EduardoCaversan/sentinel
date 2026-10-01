<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;

class MonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:120'],
            'url' => [$required, 'bail', 'required', 'string', 'max:2048', new PublicHttpUrl],
            'method' => ['sometimes', 'in:GET'],
            'expected_status_code' => ['sometimes', 'integer', 'between:100,599'],
            'interval_seconds' => ['sometimes', 'integer', 'between:60,86400', 'multiple_of:60'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,15'],
            'failure_threshold' => ['sometimes', 'integer', 'between:1,10'],
            'recovery_threshold' => ['sometimes', 'integer', 'between:1,10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
