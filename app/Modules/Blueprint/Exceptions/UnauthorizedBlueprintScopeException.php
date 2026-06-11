<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class UnauthorizedBlueprintScopeException extends Exception
{
    public function __construct()
    {
        parent::__construct('You do not have the required capability for the requested blueprint scope.');
    }
}
