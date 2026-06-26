<?php

namespace App\Modules\Blueprint\Exceptions;

use App\Exceptions\DomainException;

class UnauthorizedBlueprintScopeException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('blueprints.exceptions.unauthorized_blueprint_scope'));
    }
}
