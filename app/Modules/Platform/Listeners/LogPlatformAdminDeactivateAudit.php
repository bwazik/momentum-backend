<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminDeactivated;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminDeactivateAudit
{
    public function handle(PlatformAdminDeactivated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->deactivatedByUserId,
            'action' => AuditAction::PlatformAdminDeactivate->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->admin->public_id,
            'payload' => ['email' => $event->admin->email],
            'ip_address' => $event->ip,
        ]);
    }
}
