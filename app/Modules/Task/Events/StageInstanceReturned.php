<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceReturned implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $returnedStageInstance,
        public string $reason,
        public User $returnedByUser,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'stage.returned',
            entityType: AuditEntityType::StageInstance,
            entityId: $this->returnedStageInstance->id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->returnedStageInstance->task_id,
            rootEntityPublicId: $this->returnedStageInstance->task?->public_id,
            user: $this->returnedByUser,
            payload: ['reason' => $this->reason],
        );
    }
}
