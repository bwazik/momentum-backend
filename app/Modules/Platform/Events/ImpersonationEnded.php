<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ImpersonationEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $impersonator,
        public string $tenantPublicId,
        public string $entityId,
        public string $ip,
    ) {}
}
