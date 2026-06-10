<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminReactivated;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminReactivateAudit
{
    public function handle(PlatformAdminReactivated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->reactivatedByUserId,
            'action' => AuditAction::PlatformAdminReactivate->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->admin->public_id,
            'payload' => ['email' => $event->admin->email],
            'ip_address' => $event->ip,
        ]);
    }
}
