<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class SearchQueryTooShortException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('search.exceptions.search_query_too_short'));
    }
}
