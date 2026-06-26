<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class InvalidTransitionException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('blueprints.exceptions.invalid_transition'));
    }
}
