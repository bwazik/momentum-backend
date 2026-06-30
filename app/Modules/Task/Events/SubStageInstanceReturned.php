<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskSubStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageInstanceReturned implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskSubStageInstance $returnedSubStageInstance,
        public string $reason,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sub_stage.returned',
            entityType: AuditEntityType::SubStageInstance,
            entityId: $this->returnedSubStageInstance->id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->returnedSubStageInstance->task_id,
            rootEntityPublicId: $this->returnedSubStageInstance->task?->public_id,
            payload: ['reason' => $this->reason],
        );
    }
}
