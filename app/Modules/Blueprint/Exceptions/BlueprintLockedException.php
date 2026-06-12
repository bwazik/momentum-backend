<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class BlueprintLockedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Blueprint is locked and cannot be modified.');
    }
}
