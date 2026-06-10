<?php

namespace App\Modules\Iam\Events;

use App\Modules\Iam\Models\MonitoringScopeGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MonitoringScopeRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public MonitoringScopeGrant $grant) {}
}
