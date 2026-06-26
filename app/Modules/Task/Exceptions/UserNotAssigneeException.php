<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class UserNotAssigneeException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.user_not_assignee'));
    }
}
