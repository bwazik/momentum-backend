<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class InvalidStageSequenceException extends Exception
{
    public function __construct()
    {
        parent::__construct('Invalid stage sequence order.');
    }
}
