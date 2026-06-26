<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class RequiredSubStagesIncompleteException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.required_sub_stages_incomplete'));
    }
}
