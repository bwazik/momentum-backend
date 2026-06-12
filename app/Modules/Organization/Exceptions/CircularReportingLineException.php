<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class CircularReportingLineException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a position cannot report to itself or its descendants.');
    }
}
