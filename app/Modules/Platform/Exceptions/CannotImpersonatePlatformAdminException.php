<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class CannotImpersonatePlatformAdminException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot impersonate a platform administrator.');
    }
}
