<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class SlaTimerNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('SLA timer is not in an actionable state.');
    }
}
