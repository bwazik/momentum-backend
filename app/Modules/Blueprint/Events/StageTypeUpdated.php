<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageTypeUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $stageType) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'stage_type.updated',
            entityType: AuditEntityType::StageType,
            entityId: $this->stageType->id,
            entityPublicId: $this->stageType->public_id,
            payload: ['name_ar' => $this->stageType->name_ar],
        );
    }
}
