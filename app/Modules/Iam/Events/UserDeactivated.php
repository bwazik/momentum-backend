<?php

namespace App\Modules\Iam\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class UserDeactivated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $user) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'user.deactivated',
            entityType: AuditEntityType::User,
            entityId: $this->user->id,
            entityPublicId: $this->user->public_id,
            payload: ['name_ar' => $this->user->name_ar],
        );
    }
}
