<?php

namespace App\Modules\Platform\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ImpersonationEnded implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $impersonator,
        public string $tenantPublicId,
        public string $entityId,
        public string $ip,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'impersonation.end',
            entityType: AuditEntityType::Impersonation,
            entityId: $this->impersonator->id,
            entityPublicId: $this->impersonator->public_id,
            user: $this->impersonator,
            payload: [
                'tenant_public_id' => $this->tenantPublicId,
                'impersonated_entity_id' => $this->entityId,
                'impersonated_by_public_id' => $this->impersonator->public_id,
            ],
        );
    }
}
