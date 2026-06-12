<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class CircularDepartmentReferenceException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a department cannot be its own ancestor.');
    }
}
