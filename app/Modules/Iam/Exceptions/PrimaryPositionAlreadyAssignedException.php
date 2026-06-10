<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class PrimaryPositionAlreadyAssignedException extends Exception
{
    public function __construct()
    {
        parent::__construct('User already has an active primary position assignment. End the current one first.');
    }
}
