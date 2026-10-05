<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotificationChannel;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationRequest extends FormRequest
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
            'type' => [$required, Rule::in(['webhook', 'slack', 'discord'])],
            'endpoint' => [$required, 'bail', 'required', 'string', 'max:2048', 'starts_with:https://', new PublicHttpUrl],
            'signing_secret' => ['sometimes', 'nullable', 'string', 'min:32', 'max:128'],
            'events' => [$required, 'array', 'min:1', 'max:4'],
            'events.*' => ['required', 'distinct', Rule::in(NotificationChannel::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $channel = $this->route('channel');
            $type = $this->input('type', $channel?->type);
            $endpoint = $this->input('endpoint', $channel?->endpoint);
            $host = parse_url($endpoint ?? '', PHP_URL_HOST);
            $path = parse_url($endpoint ?? '', PHP_URL_PATH) ?? '';
            if (($type === 'slack' && ($host !== 'hooks.slack.com' || ! str_starts_with($path, '/services/'))) ||
                ($type === 'discord' && ($host !== 'discord.com' || ! str_starts_with($path, '/api/webhooks/')))) {
                $validator->errors()->add('endpoint', 'Use the official provider HTTPS webhook URL.');
            }
        }];
    }
}
