<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminUpdated;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminUpdateAudit
{
    public function handle(PlatformAdminUpdated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->updatedByUserId,
            'action' => AuditAction::PlatformAdminUpdate->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->admin->public_id,
            'payload' => $event->data,
            'ip_address' => $event->ip,
        ]);
    }
}
