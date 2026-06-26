<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class ExternalReferenceSearchNotAvailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('search.exceptions.external_reference_search_not_available'));
    }
}
