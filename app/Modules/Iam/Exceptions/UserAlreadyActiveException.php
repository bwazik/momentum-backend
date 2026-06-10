<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class UserAlreadyActiveException extends Exception
{
    public function __construct()
    {
        parent::__construct('User is already active.');
    }
}
