<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MonitoringScopeRevoked implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public MonitoringScopeGrant $grant) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'monitoring_scope.revoked',
            entityType: AuditEntityType::MonitoringScopeGrant,
            entityId: $this->grant->id,
            user: $this->grant->grantedBy,
            payload: [
                'scope_type' => $this->grant->scope_type->value,
                'scope_department_id' => $this->grant->scope_department_id,
            ],
        );
    }
}
