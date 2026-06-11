<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class InvalidTransitionException extends Exception
{
    public function __construct(string $message = 'Invalid transition definition.')
    {
        parent::__construct($message);
    }
}
