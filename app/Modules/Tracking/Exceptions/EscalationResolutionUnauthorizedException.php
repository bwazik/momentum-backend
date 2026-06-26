<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class EscalationResolutionUnauthorizedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.escalation_resolution_unauthorized'));
    }
}
