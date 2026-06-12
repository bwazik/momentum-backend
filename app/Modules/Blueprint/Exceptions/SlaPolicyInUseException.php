<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class SlaPolicyInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete SLA policy because it is referenced by one or more blueprint stages.');
    }
}
