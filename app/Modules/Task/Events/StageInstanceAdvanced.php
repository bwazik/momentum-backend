<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceAdvanced implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $completedStageInstance,
        public TaskStageInstance $newStageInstance,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'stage.advanced',
            entityType: AuditEntityType::StageInstance,
            entityId: $this->completedStageInstance->id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->completedStageInstance->task_id,
            rootEntityPublicId: $this->completedStageInstance->task?->public_id,
            payload: [
                'from_sequence' => $this->completedStageInstance->sequence_order,
                'to_sequence' => $this->newStageInstance->sequence_order,
            ],
        );
    }
}
