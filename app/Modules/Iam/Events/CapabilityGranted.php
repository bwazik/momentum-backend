<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class CapabilityGranted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Model $grant, public string $source) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'capability.granted',
            entityType: AuditEntityType::CapabilityGrant,
            entityId: $this->grant->id,
            user: $this->grant->grantedBy,
            payload: [
                'source' => $this->source,
                'capability_id' => $this->grant->capability_id,
                'user_id' => $this->grant->user_id ?? null,
                'position_id' => $this->grant->position_id ?? null,
            ],
        );
    }
}
