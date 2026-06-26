<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class DepartmentHasActivePositionsException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('organization.exceptions.department_has_active_positions'));
    }
}
