<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class SlaPolicyMissingException extends DomainException
{
    public function __construct()
    {
        parent::__construct('SLA policy not found for stage/sub-stage.');
    }
}
