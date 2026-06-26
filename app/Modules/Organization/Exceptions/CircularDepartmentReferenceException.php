<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class CircularDepartmentReferenceException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('organization.exceptions.circular_department_reference'));
    }
}
