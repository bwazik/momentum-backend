<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ConfidentialMetadataViewed implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: ConfidentialAccessEventType::MetadataView->auditEventType(),
            entityType: AuditEntityType::Task,
            entityId: $this->task->id,
            entityPublicId: $this->task->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->task->id,
            rootEntityPublicId: $this->task->public_id,
            user: $this->user,
            payload: [],
        );
    }
}
