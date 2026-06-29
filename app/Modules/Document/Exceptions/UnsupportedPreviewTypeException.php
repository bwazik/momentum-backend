<?php

namespace App\Modules\Document\Exceptions;

use App\Exceptions\DomainException;

class UnsupportedPreviewTypeException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This file type cannot be previewed inline.');
    }
}
