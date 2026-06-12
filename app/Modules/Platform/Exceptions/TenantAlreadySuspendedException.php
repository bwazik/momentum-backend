<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class TenantAlreadySuspendedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This tenant is already suspended.');
    }
}
