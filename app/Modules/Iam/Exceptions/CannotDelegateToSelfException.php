<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class CannotDelegateToSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delegate authority to yourself.');
    }
}
