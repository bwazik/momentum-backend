<?php

namespace App\Modules\FollowUp\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\FollowUp\Models\FollowUpAction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class FollowUpActionCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FollowUpAction $action) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'follow_up_action.created',
            entityType: AuditEntityType::FollowUpAction,
            entityId: $this->action->id,
            entityPublicId: $this->action->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->action->task_id,
            user: $this->action->user,
            payload: ['action_type' => $this->action->action_type?->apiValue(), 'note_ar' => $this->action->note_ar],
        );
    }
}
