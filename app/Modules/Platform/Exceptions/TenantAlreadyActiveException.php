<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class TenantAlreadyActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('platform.exceptions.tenant_already_active'));
    }
}
