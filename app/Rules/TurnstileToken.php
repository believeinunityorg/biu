<?php

namespace App\Rules;

use App\Services\TurnstileService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TurnstileToken implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $service = app(TurnstileService::class);

        if (! $service->isEnabled()) {
            return;
        }

        if (! is_string($value) || ! $service->verify($value, request()->ip())) {
            $fail('Bot verification failed. Please refresh the challenge and try again.');
        }
    }
}
