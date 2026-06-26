<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class InvalidStageSequenceException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.exceptions.invalid_stage_sequence'));
    }
}
