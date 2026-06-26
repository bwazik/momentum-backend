<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class SubStageNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.sub_stage_not_active'));
    }
}
