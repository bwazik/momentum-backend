<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskNotVisibleException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct(__('task.exceptions.task_not_visible'));
    }
}
