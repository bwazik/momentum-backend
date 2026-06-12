<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class CannotRevokeSystemCapabilityKeyException extends DomainException
{
    public function __construct()
    {
        parent::__construct('System-defined capability keys cannot be modified.');
    }
}
