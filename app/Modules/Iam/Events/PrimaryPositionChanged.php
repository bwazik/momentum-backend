<?php

namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\UserPositionAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PrimaryPositionChanged implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public UserPositionAssignment $assignment) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'primary_position.changed',
            entityType: AuditEntityType::PositionAssignment,
            entityId: $this->assignment->id,
            user: $this->assignment->user,
            payload: [
                'user_id' => $this->assignment->user_id,
                'position_id' => $this->assignment->position_id,
            ],
        );
    }
}
