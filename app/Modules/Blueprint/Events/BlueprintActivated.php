<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintActivated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $blueprint) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'blueprint.activated',
            entityType: AuditEntityType::Blueprint,
            entityId: $this->blueprint->id,
            entityPublicId: $this->blueprint->public_id,
            payload: [],
        );
    }
}
