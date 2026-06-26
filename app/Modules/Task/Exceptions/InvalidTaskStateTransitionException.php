<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidTaskStateTransitionException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('task.exceptions.invalid_task_state_transition'));
    }
}
