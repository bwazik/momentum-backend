<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformAdminDeactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $admin,
        public int $deactivatedByUserId,
        public string $ip,
    ) {}
}
