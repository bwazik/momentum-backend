<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class DepartmentHasActivePositionsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete a department that has active positions.');
    }
}
