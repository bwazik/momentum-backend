<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class InvalidBlueprintScopeException extends Exception
{
    public function __construct()
    {
        parent::__construct('Department ID is required when blueprint scope is department.');
    }
}
