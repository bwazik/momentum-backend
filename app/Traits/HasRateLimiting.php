<?php

namespace App\Traits;

use App\Exceptions\ThrottleException;
use App\Support\RateLimits;
use Illuminate\Support\Facades\RateLimiter;

trait HasRateLimiting
{
    protected function checkRateLimit(string $limiterName, string|array $keyParts): void
    {
        $maxAttempts = RateLimits::attempts($limiterName);
        $decayMinutes = RateLimits::decayMinutes($limiterName);
        $key = $this->buildRateLimitKey($limiterName, $keyParts);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            throw new ThrottleException($limiterName, $seconds);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }

    protected function remainingAttempts(string $limiterName, string|array $keyParts): int
    {
        $key = $this->buildRateLimitKey($limiterName, $keyParts);

        return RateLimiter::remaining($key, RateLimits::attempts($limiterName));
    }

    protected function clearRateLimit(string $limiterName, string|array $keyParts): void
    {
        $key = $this->buildRateLimitKey($limiterName, $keyParts);

        RateLimiter::clear($key);
    }

    private function buildRateLimitKey(string $limiterName, string|array $parts): string
    {
        $parts = is_array($parts) ? $parts : [$parts];

        return $limiterName.':'.implode('|', array_map('strtolower', $parts));
    }
}
