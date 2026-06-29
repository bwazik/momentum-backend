<?php

namespace App\Modules\Document\Exceptions;

use App\Exceptions\DomainException;

class StorageProviderException extends DomainException
{
    protected int $statusCode = 500;

    public function __construct()
    {
        parent::__construct('An error occurred while accessing the file storage provider.');
    }
}
