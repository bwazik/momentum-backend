<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class DelegationScopeMismatchException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('iam.exceptions.delegation_scope_mismatch'));
    }
}
