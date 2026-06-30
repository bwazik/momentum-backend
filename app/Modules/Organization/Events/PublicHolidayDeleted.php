<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\PublicHoliday;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PublicHolidayDeleted implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PublicHoliday $publicHoliday) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'public_holiday.deleted',
            entityType: AuditEntityType::PublicHoliday,
            entityId: $this->publicHoliday->id,
            entityPublicId: $this->publicHoliday->public_id,
            payload: [
                'name_ar' => $this->publicHoliday->name_ar,
                'name_en' => $this->publicHoliday->name_en,
            ],
        );
    }
}
