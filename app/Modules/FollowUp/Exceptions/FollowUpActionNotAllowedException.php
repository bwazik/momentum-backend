<?php

namespace App\Modules\FollowUp\Exceptions;

use App\Exceptions\DomainException;

class FollowUpActionNotAllowedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct('You do not have permission to log follow-up actions.');
    }
}
