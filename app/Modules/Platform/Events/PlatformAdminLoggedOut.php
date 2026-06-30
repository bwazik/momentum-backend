<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformAdminLoggedOut implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public string $ip,
        public bool $allDevices,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'platform_admin.logged_out',
            entityType: AuditEntityType::PlatformAdmin,
            entityId: $this->user->id,
            entityPublicId: $this->user->public_id,
            payload: ['email' => $this->user->email, 'all_devices' => $this->allDevices],
        );
    }
}
