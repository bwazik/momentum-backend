<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class CircularReportingLineException extends Exception
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a position cannot report to itself or its descendants.');
    }
}
