<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\AuditGrant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuditGrantRevoked implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuditGrant $grant) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'audit_grant.revoked',
            entityType: AuditEntityType::AuditGrant,
            entityId: $this->grant->id,
            user: $this->grant->grantedBy,
            payload: ['external_auditor_id' => $this->grant->external_auditor_user_id],
        );
    }
}
