<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class CannotDelegateToSelfException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('iam.exceptions.cannot_delegate_to_self'));
    }
}
