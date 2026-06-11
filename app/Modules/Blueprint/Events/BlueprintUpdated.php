<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Blueprint\Models\Blueprint;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Blueprint $blueprint) {}
}
