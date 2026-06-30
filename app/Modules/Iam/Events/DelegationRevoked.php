<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\Delegation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DelegationRevoked implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delegation $delegation) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'delegation.revoked',
            entityType: AuditEntityType::Delegation,
            entityId: $this->delegation->id,
            entityPublicId: $this->delegation->public_id,
            user: $this->delegation->delegator,
            payload: [
                'delegator_user_id' => $this->delegation->delegator_user_id,
                'delegate_user_id' => $this->delegation->delegate_user_id,
                'scope_type' => $this->delegation->scope_type->value,
            ],
        );
    }
}
