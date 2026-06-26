<?php

namespace App\Modules\Platform\Exceptions;

use App\Exceptions\DomainException;

class PlatformAdminCannotDeactivateSelfException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('platform.exceptions.platform_admin_cannot_deactivate_self'));
    }
}
