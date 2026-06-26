<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;

class AnalyticsScopeDeniedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('analytics.exceptions.analytics_scope_denied'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
