<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class CannotDelegateToSelfException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delegate authority to yourself.');
    }
}
