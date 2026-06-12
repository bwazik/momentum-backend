<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class DepartmentHasChildrenException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete a department that has child departments.');
    }
}
