<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformAdminLoggedOut implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public string $ip,
        public bool $allDevices,
    ) {}
}
