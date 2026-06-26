<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class UnresolvableAssignmentException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('task.exceptions.unresolvable_assignment'));
    }
}
