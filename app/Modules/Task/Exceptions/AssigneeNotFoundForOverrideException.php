<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class AssigneeNotFoundForOverrideException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.assignee_not_found_for_override'));
    }
}
