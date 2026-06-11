<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class StageTypeInUseException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete stage type because it is referenced by one or more blueprint stages.');
    }
}
