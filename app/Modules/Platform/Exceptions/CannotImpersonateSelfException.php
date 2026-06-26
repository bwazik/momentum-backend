<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class CannotImpersonateSelfException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('platform.exceptions.cannot_impersonate_self'));
    }
}
