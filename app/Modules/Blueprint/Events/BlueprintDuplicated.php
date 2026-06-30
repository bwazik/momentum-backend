<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintDuplicated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $blueprint, public $sourceBlueprint) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'blueprint.duplicated',
            entityType: AuditEntityType::Blueprint,
            entityId: $this->blueprint->id,
            entityPublicId: $this->blueprint->public_id,
            payload: ['source_public_id' => $this->sourceBlueprint->public_id],
        );
    }
}
