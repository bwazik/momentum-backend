<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class SlaPolicyInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.catalog.sla_policy_in_use'));
    }
}
