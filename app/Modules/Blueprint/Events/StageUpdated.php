<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $stage) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'stage.updated',
            entityType: AuditEntityType::BlueprintStage,
            entityId: $this->stage->id,
            entityPublicId: $this->stage->public_id,
            payload: ['name_ar' => $this->stage->name_ar],
        );
    }
}
