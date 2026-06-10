<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ImpersonationStarted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $impersonator,
        public User $targetUser,
        public string $tenantPublicId,
        public string $tenantSlug,
        public string $ip,
    ) {}
}
