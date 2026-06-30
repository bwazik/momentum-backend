<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\Department;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DepartmentUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Department $department) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'department.updated',
            entityType: AuditEntityType::Department,
            entityId: $this->department->id,
            entityPublicId: $this->department->public_id,
            payload: ['name_ar' => $this->department->name_ar, 'name_en' => $this->department->name_en],
        );
    }
}
