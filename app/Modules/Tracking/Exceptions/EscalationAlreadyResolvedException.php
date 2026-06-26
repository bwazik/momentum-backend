<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class EscalationAlreadyResolvedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.escalation_already_resolved'));
    }
}
