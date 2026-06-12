<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class AuthorityGradeHasActivePositionsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Cannot delete an authority grade that is referenced by active positions.');
    }
}
