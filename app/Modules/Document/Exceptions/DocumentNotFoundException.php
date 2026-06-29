<?php

namespace App\Modules\Document\Exceptions;

use App\Exceptions\DomainException;

class DocumentNotFoundException extends DomainException
{
    protected int $statusCode = 404;

    public function __construct()
    {
        parent::__construct('Document not found.');
    }
}
