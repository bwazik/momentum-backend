<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class PrimaryPositionAlreadyAssignedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('iam.exceptions.primary_position_already_assigned'));
    }
}
