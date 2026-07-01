<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ExternalReferenceDeleted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskExternalReference $reference,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'external_reference.deleted',
            entityType: AuditEntityType::ExternalReference,
            entityId: $this->reference->id,
            entityPublicId: $this->reference->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->reference->task_id,
            rootEntityPublicId: $this->reference->task?->public_id,
            user: $this->user,
            payload: [
                'reference_type' => $this->reference->reference_type?->name,
                'reference_number' => $this->reference->reference_number,
            ],
        );
    }
}
