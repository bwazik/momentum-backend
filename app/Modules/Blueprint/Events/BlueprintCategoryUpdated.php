<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintCategoryUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $blueprintCategory) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'blueprint_category.updated',
            entityType: AuditEntityType::BlueprintCategory,
            entityId: $this->blueprintCategory->id,
            entityPublicId: $this->blueprintCategory->public_id,
            payload: ['name_ar' => $this->blueprintCategory->name_ar],
        );
    }
}
