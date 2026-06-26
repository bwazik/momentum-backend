<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class DuplicateOpenEscalationException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.duplicate_open_escalation'));
    }
}
