<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\WorkingCalendar;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkingCalendarUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public WorkingCalendar $calendar) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'working_calendar.updated',
            entityType: AuditEntityType::WorkingCalendar,
            entityId: $this->calendar->id,
            entityPublicId: $this->calendar->public_id,
            payload: ['name_ar' => $this->calendar->name_ar, 'name_en' => $this->calendar->name_en],
        );
    }
}
