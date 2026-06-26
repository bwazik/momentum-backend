<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class SlaPolicyMissingException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('tracking.exceptions.sla_policy_missing'));
    }
}
