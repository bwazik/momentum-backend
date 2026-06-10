<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ThrottleException extends Exception
{
    public function __construct(
        public readonly string $limiterName,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            __('Too many requests. Please try again in :seconds seconds.', ['seconds' => $retryAfterSeconds])
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage()],
            429,
            ['Retry-After' => $this->retryAfterSeconds]
        );
    }
}
