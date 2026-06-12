<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

abstract class DomainException extends Exception
{
    protected int $statusCode = 422;

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->statusCode);
    }
}
