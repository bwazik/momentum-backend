<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskStageInstance $stageInstance) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'stage.created',
            entityType: AuditEntityType::StageInstance,
            entityId: $this->stageInstance->id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->stageInstance->task_id,
            rootEntityPublicId: $this->stageInstance->task?->public_id,
            payload: [
                'stage_name_ar' => $this->stageInstance->blueprintStage?->name_ar,
                'sequence_order' => $this->stageInstance->sequence_order,
            ],
        );
    }
}
