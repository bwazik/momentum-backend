<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class CannotRevokeSystemCapabilityKeyException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('iam.exceptions.cannot_revoke_system_capability_key'));
    }
}
