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

class ConfidentialParticipantRemoved implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public User $participant,
        public User $removedBy,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: ConfidentialAccessEventType::ParticipantRemoved->auditEventType(),
            entityType: AuditEntityType::Task,
            entityId: $this->task->id,
            entityPublicId: $this->task->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->task->id,
            rootEntityPublicId: $this->task->public_id,
            user: $this->removedBy,
            payload: ['participant_public_id' => $this->participant->public_id],
        );
    }
}
