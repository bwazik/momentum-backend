<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageAssignmentCompleted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskStageAssignment $assignment) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sub_stage_assignment.completed',
            entityType: AuditEntityType::SubStageInstance,
            entityId: $this->assignment->sub_stage_instance_id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->assignment->task_id,
            rootEntityPublicId: $this->assignment->task?->public_id,
            payload: ['completion_note' => $this->assignment->completion_note],
        );
    }
}
