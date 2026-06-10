<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class CannotRevokeSystemCapabilityKeyException extends Exception
{
    public function __construct()
    {
        parent::__construct('System-defined capability keys cannot be modified.');
    }
}
