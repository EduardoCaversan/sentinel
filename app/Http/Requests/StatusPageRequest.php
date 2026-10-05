<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\OrganizationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:120'],
            'slug' => [$required, 'required', 'string', 'min:3', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('status_pages', 'slug')->ignore($this->route('statusPage')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_published' => ['sometimes', 'boolean'],
            'components' => [$required, 'array', 'min:1', 'max:10'],
            'components.*.monitor_id' => ['required', 'integer', 'distinct', Rule::exists('monitors', 'id')->where('organization_id', OrganizationAccess::organization($this)->id)],
            'components.*.name' => ['required', 'string', 'max:120'],
        ];
    }
}
