<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class UserAlreadyDeactivatedException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('iam.exceptions.user_already_deactivated'));
    }
}
