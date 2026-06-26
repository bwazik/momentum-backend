<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskNotSuspendedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.task_not_suspended'));
    }
}
