<?php

namespace App\Modules\Platform\Events;

use App\Models\Tenant;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TenantProvisioned implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant,
        public int $adminUserId,
        public string $ip,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'tenant.provisioned',
            entityType: AuditEntityType::Tenant,
            entityId: $this->tenant->id,
            entityPublicId: $this->tenant->public_id,
            payload: ['slug' => $this->tenant->slug, 'name' => $this->tenant->name_en],
        );
    }
}
