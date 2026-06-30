<?php

namespace App\Modules\Organization\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Organization\Models\AuthorityGrade;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuthorityGradeUpdated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuthorityGrade $authorityGrade) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'authority_grade.updated',
            entityType: AuditEntityType::AuthorityGrade,
            entityId: $this->authorityGrade->id,
            entityPublicId: $this->authorityGrade->public_id,
            payload: [
                'name_ar' => $this->authorityGrade->name_ar,
                'name_en' => $this->authorityGrade->name_en,
            ],
        );
    }
}
