<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskNotDraftException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Task is not in draft status.');
    }
}
