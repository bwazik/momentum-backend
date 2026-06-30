<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\PublicHoliday;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PublicHolidayCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PublicHoliday $holiday) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'public_holiday.created',
            entityType: AuditEntityType::PublicHoliday,
            entityId: $this->holiday->id,
            entityPublicId: $this->holiday->public_id,
            payload: [
                'name_ar' => $this->holiday->name_ar,
                'name_en' => $this->holiday->name_en,
            ],
        );
    }
}
