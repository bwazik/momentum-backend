<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageAssignmentOverridden implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageAssignment $oldAssignment,
        public TaskStageAssignment $newAssignment,
        public string $reason,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'assignment.overridden',
            entityType: AuditEntityType::StageInstance,
            entityId: $this->oldAssignment->stage_instance_id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->oldAssignment->task_id,
            rootEntityPublicId: $this->oldAssignment->task?->public_id,
            payload: [
                'reason' => $this->reason,
                'old_assignee' => $this->oldAssignment->user?->public_id,
                'new_assignee' => $this->newAssignment->user?->public_id,
            ],
        );
    }
}
