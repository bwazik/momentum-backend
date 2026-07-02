<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class ConfidentialAccessDeniedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct(__('task.exceptions.confidential_access_denied'));
    }
}
