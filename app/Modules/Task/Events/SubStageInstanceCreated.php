<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskSubStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageInstanceCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskSubStageInstance $subStageInstance) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sub_stage.created',
            entityType: AuditEntityType::SubStageInstance,
            entityId: $this->subStageInstance->id,
            entityPublicId: null,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->subStageInstance->task_id,
            rootEntityPublicId: $this->subStageInstance->task?->public_id,
            payload: [
                'sub_stage_name_ar' => $this->subStageInstance->blueprintSubStage?->name_ar,
            ],
        );
    }
}
