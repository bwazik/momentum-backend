<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class BlueprintCategoryInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete category because it is referenced by one or more blueprints.');
    }
}
