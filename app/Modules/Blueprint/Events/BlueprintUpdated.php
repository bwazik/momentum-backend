<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Blueprint\Models\Blueprint;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Blueprint $blueprint) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'blueprint.updated',
            entityType: AuditEntityType::Blueprint,
            entityId: $this->blueprint->id,
            entityPublicId: $this->blueprint->public_id,
            payload: ['name_ar' => $this->blueprint->name_ar, 'name_en' => $this->blueprint->name_en],
        );
    }
}
