<?php

namespace App\Modules\Task\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskPriority;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskPriorityUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskPriority $priority) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'task_priority.updated',
            entityType: AuditEntityType::Task,
            entityId: $this->priority->id,
            entityPublicId: $this->priority->public_id ?? null,
            payload: ['name_ar' => $this->priority->name_ar],
        );
    }
}
