<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class BlueprintLockedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.exceptions.blueprint_locked'));
    }
}
