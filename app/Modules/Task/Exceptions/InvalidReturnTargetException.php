<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidReturnTargetException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid return target: no return transition defined for this stage.');
    }
}
