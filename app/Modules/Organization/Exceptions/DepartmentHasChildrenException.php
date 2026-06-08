<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class DepartmentHasChildrenException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete a department that has child departments.');
    }
}
