<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class CannotImpersonatePlatformAdminException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot impersonate a platform administrator.');
    }
}
