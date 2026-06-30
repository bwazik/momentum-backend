<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubStageReordered implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $subStage) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sub_stage.reordered',
            entityType: AuditEntityType::BlueprintSubStage,
            entityId: $this->subStage->id,
            entityPublicId: $this->subStage->public_id,
            payload: ['name_ar' => $this->subStage->name_ar],
        );
    }
}
