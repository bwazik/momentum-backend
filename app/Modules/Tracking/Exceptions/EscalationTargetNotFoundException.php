<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class EscalationTargetNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.escalation_target_not_found'));
    }
}
