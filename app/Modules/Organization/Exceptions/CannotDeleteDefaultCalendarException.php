<?php

namespace App\Modules\Organization\Exceptions;

use App\Exceptions\DomainException;

class CannotDeleteDefaultCalendarException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('organization.exceptions.cannot_delete_default_calendar'));
    }
}
