<?php

namespace App\Modules\Platform\Events;

use App\Models\Tenant;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TenantMigrated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant,
        public int $adminUserId,
        public string $ip,
        public string $status,
        public ?string $error = null,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'tenant.run_migrations',
            entityType: AuditEntityType::Tenant,
            entityId: $this->tenant->id,
            entityPublicId: $this->tenant->public_id,
            payload: [
                'slug' => $this->tenant->slug,
                'name' => $this->tenant->name_en,
                'status' => $this->status,
            ],
        );
    }
}
