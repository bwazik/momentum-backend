<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class SearchQueryTooShortException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Search query must be at least 2 characters.');
    }
}
