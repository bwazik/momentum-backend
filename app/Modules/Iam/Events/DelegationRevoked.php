<?php

namespace App\Modules\Iam\Events;

use App\Modules\Iam\Models\Delegation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DelegationRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delegation $delegation) {}
}
