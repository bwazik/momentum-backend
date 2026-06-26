<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class MissingManualAssignmentException extends DomainException
{
    public function __construct(string $name = '')
    {
        parent::__construct(__('task.exceptions.manual_assignment_required', ['name' => $name]));
    }
}
