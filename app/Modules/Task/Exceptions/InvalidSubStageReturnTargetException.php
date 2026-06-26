<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidSubStageReturnTargetException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.invalid_sub_stage_return_target'));
    }
}
