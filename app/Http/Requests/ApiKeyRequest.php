<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\OrganizationApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'scopes' => ['required', 'array', 'min:1', 'max:11'], 'scopes.*' => ['required', 'distinct', Rule::in(OrganizationApiKey::SCOPES)], 'expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addYear()->toISOString()]];
    }
}
