<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class CircularDepartmentReferenceException extends Exception
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a department cannot be its own ancestor.');
    }
}
