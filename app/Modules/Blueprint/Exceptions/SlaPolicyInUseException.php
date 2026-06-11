<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class SlaPolicyInUseException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete SLA policy because it is referenced by one or more blueprint stages.');
    }
}
