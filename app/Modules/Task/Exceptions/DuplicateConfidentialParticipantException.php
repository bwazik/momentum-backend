<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class DuplicateConfidentialParticipantException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.duplicate_confidential_participant'));
    }
}
