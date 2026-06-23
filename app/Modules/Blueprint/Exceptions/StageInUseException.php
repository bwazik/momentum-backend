<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class StageInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.catalog.stage_in_use'));
    }
}
