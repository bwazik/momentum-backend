<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class BlueprintLockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Blueprint is locked and cannot be modified.');
    }
}
