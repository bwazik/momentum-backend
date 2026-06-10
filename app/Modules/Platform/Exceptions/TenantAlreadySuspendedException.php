<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class TenantAlreadySuspendedException extends Exception
{
    public function __construct()
    {
        parent::__construct('This tenant is already suspended.');
    }
}
