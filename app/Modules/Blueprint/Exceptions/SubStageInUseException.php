<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class SubStageInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.catalog.sub_stage_in_use'));
    }
}
