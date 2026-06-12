<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class StageNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Stage instance is not in active status.');
    }
}
