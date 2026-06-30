<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\Position;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PositionTransferred implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Position $position) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'position.transferred',
            entityType: AuditEntityType::Position,
            entityId: $this->position->id,
            entityPublicId: $this->position->public_id,
            payload: [
                'title_ar' => $this->position->title_ar,
                'title_en' => $this->position->title_en,
            ],
        );
    }
}
