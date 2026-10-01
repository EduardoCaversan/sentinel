<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\UnsafeTarget;
use App\Services\PublicTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(PublicTarget::class)->resolve((string) $value);
        } catch (UnsafeTarget $exception) {
            $fail($exception->getMessage());
        }
    }
}
