<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class StageNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.stage_not_active'));
    }
}
