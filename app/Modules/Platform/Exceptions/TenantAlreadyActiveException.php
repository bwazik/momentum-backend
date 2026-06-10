<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class TenantAlreadyActiveException extends Exception
{
    public function __construct()
    {
        parent::__construct('This tenant is already active.');
    }
}
