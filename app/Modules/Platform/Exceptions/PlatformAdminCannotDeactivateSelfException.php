<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class PlatformAdminCannotDeactivateSelfException extends DomainException
{
    public function __construct()
    {
        parent::__construct('You cannot deactivate your own account.');
    }
}
