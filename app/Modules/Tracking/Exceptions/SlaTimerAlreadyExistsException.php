<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class SlaTimerAlreadyExistsException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.sla_timer_already_exists'));
    }
}
