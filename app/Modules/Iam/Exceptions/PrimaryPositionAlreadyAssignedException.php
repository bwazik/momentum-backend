<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class PrimaryPositionAlreadyAssignedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('User already has an active primary position assignment. End the current one first.');
    }
}
