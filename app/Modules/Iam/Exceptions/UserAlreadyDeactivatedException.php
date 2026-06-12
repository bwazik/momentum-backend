<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class UserAlreadyDeactivatedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('User is already deactivated.');
    }
}
