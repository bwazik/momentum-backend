<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class InvalidGovernanceScopeException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('iam.exceptions.invalid_governance_scope'));
    }
}
