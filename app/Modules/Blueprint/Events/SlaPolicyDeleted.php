<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SlaPolicyDeleted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public $slaPolicy) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'sla_policy.deleted',
            entityType: AuditEntityType::SlaPolicy,
            entityId: $this->slaPolicy->id,
            entityPublicId: $this->slaPolicy->public_id,
            payload: ['name_ar' => $this->slaPolicy->name_ar],
        );
    }
}
