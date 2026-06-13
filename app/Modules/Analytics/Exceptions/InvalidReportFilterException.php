<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;

class InvalidReportFilterException extends DomainException
{
    public function __construct(string $message = 'Invalid report filter.')
    {
        parent::__construct($message);
    }
}
