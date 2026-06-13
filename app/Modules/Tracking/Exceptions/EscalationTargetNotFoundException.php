<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class EscalationTargetNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('No escalation target could be resolved for this stage.');
    }
}
