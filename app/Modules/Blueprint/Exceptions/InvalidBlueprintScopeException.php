<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class InvalidBlueprintScopeException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Department ID is required when blueprint scope is department.');
    }
}
