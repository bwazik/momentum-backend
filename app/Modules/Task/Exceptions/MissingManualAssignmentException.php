<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class MissingManualAssignmentException extends DomainException
{
    public function __construct(string $message = 'Manual assignment is required but none were provided.')
    {
        parent::__construct($message);
    }
}
