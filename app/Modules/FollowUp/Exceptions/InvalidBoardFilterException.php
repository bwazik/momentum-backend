<?php

namespace App\Modules\FollowUp\Exceptions;

use App\Exceptions\DomainException;

class InvalidBoardFilterException extends DomainException
{
    protected int $statusCode = 422;

    public function __construct(string $message = 'Invalid board filter value.')
    {
        parent::__construct($message);
    }
}
