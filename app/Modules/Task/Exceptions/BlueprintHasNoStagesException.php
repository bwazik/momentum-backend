<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class BlueprintHasNoStagesException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Blueprint has no stages defined.');
    }
}
