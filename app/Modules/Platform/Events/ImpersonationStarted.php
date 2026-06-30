<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ImpersonationStarted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $impersonator,
        public User $targetUser,
        public string $tenantPublicId,
        public string $tenantSlug,
        public string $ip,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'impersonation.start',
            entityType: AuditEntityType::Impersonation,
            entityId: $this->impersonator->id,
            entityPublicId: $this->impersonator->public_id,
            user: $this->impersonator,
            payload: [
                'target_user_id' => $this->targetUser->public_id,
                'target_email' => $this->targetUser->email,
                'tenant_public_id' => $this->tenantPublicId,
                'tenant_slug' => $this->tenantSlug,
                'impersonated_by_public_id' => $this->impersonator->public_id,
            ],
        );
    }
}
