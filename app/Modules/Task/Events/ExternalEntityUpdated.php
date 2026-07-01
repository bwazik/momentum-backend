<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\ExternalEntity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ExternalEntityUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public ExternalEntity $entity,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'external_entity.updated',
            entityType: AuditEntityType::ExternalEntity,
            entityId: $this->entity->id,
            entityPublicId: $this->entity->public_id,
            rootEntityType: null,
            rootEntityId: null,
            rootEntityPublicId: null,
            user: $this->user,
            payload: [
                'name_ar' => $this->entity->name_ar,
                'entity_type' => $this->entity->entity_type?->name,
            ],
        );
    }
}
