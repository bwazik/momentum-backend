<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class UnauthorizedBlueprintScopeException extends DomainException
{
    public function __construct()
    {
        parent::__construct('You do not have the required capability for the requested blueprint scope.');
    }
}
