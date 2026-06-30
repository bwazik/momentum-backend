<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformAdminDeactivated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $admin,
        public int $deactivatedByUserId,
        public string $ip,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'platform_admin.deactivated',
            entityType: AuditEntityType::PlatformAdmin,
            entityId: $this->admin->id,
            entityPublicId: $this->admin->public_id,
            payload: ['email' => $this->admin->email],
        );
    }
}
