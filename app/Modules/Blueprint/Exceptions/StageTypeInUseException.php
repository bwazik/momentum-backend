<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class StageTypeInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.catalog.stage_type_in_use'));
    }
}
