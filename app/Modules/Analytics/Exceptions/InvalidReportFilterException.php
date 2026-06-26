<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;

class InvalidReportFilterException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('analytics.exceptions.invalid_report_filter'));
    }
}
