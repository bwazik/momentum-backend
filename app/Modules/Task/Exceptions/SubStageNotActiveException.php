<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class SubStageNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Sub-stage instance is not in active status.');
    }
}
