<?php

namespace App\Modules\Iam\Events;

use App\Modules\Iam\Models\AuditGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuditGrantRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuditGrant $grant) {}
}
