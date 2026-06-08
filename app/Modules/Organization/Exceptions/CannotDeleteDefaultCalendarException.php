<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class CannotDeleteDefaultCalendarException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete the default working calendar.');
    }
}
