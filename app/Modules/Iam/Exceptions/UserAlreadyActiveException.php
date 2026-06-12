<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class UserAlreadyActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('User is already active.');
    }
}
