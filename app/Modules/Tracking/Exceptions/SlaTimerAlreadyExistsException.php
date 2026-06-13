<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class SlaTimerAlreadyExistsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('An active SLA timer already exists for this stage instance.');
    }
}
