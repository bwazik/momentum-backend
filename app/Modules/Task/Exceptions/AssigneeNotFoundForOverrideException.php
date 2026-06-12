<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class AssigneeNotFoundForOverrideException extends DomainException
{
    public function __construct()
    {
        parent::__construct('User is not an active assignee that can be overridden.');
    }
}
