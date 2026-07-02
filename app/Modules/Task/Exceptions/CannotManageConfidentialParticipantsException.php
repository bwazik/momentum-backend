<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class CannotManageConfidentialParticipantsException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.cannot_manage_confidential_participants'));
    }
}
