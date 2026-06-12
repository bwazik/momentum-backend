<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class InvalidTransitionException extends DomainException
{
    public function __construct(string $message = 'Invalid transition definition.')
    {
        parent::__construct($message);
    }
}
