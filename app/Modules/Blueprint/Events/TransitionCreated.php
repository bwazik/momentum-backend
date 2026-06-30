<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TransitionCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $transition) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'transition.created',
            entityType: AuditEntityType::BlueprintTransition,
            entityId: $this->transition->id,
            entityPublicId: $this->transition->public_id,
            payload: ['transition_id' => $this->transition->id],
        );
    }
}
