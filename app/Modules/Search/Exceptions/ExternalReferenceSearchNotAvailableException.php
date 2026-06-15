<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class ExternalReferenceSearchNotAvailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct('External reference search is not yet available.');
    }
}
