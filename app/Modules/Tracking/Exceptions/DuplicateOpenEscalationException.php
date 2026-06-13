<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class DuplicateOpenEscalationException extends DomainException
{
    public function __construct()
    {
        parent::__construct('An open escalation already exists for this stage from this user.');
    }
}
