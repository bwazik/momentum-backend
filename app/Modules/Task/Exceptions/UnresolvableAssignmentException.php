<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class UnresolvableAssignmentException extends DomainException
{
    public function __construct(string $message = 'Cannot resolve assignee for this stage.')
    {
        parent::__construct($message);
    }
}
