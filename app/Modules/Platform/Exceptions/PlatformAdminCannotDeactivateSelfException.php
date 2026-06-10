<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class PlatformAdminCannotDeactivateSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot deactivate your own account.');
    }
}
