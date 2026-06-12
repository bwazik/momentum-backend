<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class BlueprintNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Blueprint is not active.');
    }
}
