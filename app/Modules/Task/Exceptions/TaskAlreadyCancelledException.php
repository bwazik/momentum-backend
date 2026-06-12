<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskAlreadyCancelledException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Task is already cancelled.');
    }
}
