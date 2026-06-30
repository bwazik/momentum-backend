<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Tracking\Models\Escalation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EscalationResolved implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Escalation $escalation) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'escalation.resolved',
            entityType: AuditEntityType::Escalation,
            entityId: $this->escalation->id,
            entityPublicId: $this->escalation->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->escalation->task_id,
            rootEntityPublicId: $this->escalation->task?->public_id,
            payload: ['resolution_note' => $this->escalation->resolution_note],
        );
    }
}
