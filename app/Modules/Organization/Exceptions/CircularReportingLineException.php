<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class CircularReportingLineException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('organization.exceptions.circular_reporting_line'));
    }
}
