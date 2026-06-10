<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class CannotImpersonateSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot impersonate yourself.');
    }
}
