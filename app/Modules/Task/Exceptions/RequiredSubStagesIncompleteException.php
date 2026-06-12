<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class RequiredSubStagesIncompleteException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot complete stage: required sub-stages are not all completed.');
    }
}
