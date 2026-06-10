<?php

namespace App\Modules\Platform\Events;

use App\Models\Tenant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TenantMigrated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant,
        public int $adminUserId,
        public string $ip,
        public string $status,
        public ?string $error = null,
    ) {}
}
