<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SlaTimerResumed implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SlaTimerInstance $timer) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sla_timer.resumed',
            entityType: AuditEntityType::SlaTimerInstance,
            entityId: $this->timer->id,
            entityPublicId: $this->timer->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->timer->task_id,
            rootEntityPublicId: $this->timer->task?->public_id,
            payload: [],
        );
    }
}
