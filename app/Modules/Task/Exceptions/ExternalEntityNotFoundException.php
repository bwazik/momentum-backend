<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class ExternalEntityNotFoundException extends DomainException
{
    protected int $statusCode = 404;

    public function __construct()
    {
        parent::__construct(__('task.exceptions.external_entity_not_found'));
    }
}
