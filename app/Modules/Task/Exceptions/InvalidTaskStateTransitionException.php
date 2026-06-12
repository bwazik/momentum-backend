<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidTaskStateTransitionException extends DomainException
{
    public function __construct(string $message = 'Invalid task state transition.')
    {
        parent::__construct($message);
    }
}
