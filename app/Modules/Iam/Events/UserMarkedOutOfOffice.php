<?php

namespace App\Modules\Iam\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class UserMarkedOutOfOffice implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
