<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageAssignmentCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskStageAssignment $assignment) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'assignment.created',
            entityType: AuditEntityType::StageInstance,
            entityId: $this->assignment->stage_instance_id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->assignment->task_id,
            rootEntityPublicId: $this->assignment->task?->public_id,
            payload: [
                'assignee_public_id' => $this->assignment->user?->public_id,
                'role' => $this->assignment->assignment_role?->apiValue(),
            ],
        );
    }
}
