<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class StageTypeInUseException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete stage type because it is referenced by one or more blueprint stages.');
    }
}
