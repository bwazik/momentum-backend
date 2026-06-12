<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidSubStageReturnTargetException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid sub-stage return target: must be an earlier sub-stage in the same parent stage.');
    }
}
