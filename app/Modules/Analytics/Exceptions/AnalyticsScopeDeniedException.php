<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;

class AnalyticsScopeDeniedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This action requires an analytics capability.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
