<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class AuthorityGradeHasActivePositionsException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete an authority grade that is referenced by active positions.');
    }
}
