<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class GovernanceParticipantNotFoundException extends DomainException
{
    protected int $statusCode = 404;

    public function __construct()
    {
        parent::__construct(__('task.exceptions.governance_participant_not_found'));
    }
}
