<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class MissingManualAssignmentException extends DomainException
{
    public function __construct(string $name = '', bool $isSubStage = false)
    {
        $key = $isSubStage ? 'task.exceptions.manual_assignment_required_sub' : 'task.exceptions.manual_assignment_required';
        parent::__construct(__($key, ['name' => $name]));
    }
}
