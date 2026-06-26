<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskAlreadyCancelledException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.task_already_cancelled'));
    }
}
