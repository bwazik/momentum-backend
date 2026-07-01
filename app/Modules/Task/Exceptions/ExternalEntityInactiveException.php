<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class ExternalEntityInactiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.external_entity_inactive'));
    }
}
