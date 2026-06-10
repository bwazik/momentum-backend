<?php

namespace App\Modules\Iam\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class CapabilityRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Model $grant, public string $source) {}
}
