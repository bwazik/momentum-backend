<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminCreated;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminCreateAudit
{
    public function handle(PlatformAdminCreated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->createdByUserId,
            'action' => AuditAction::PlatformAdminCreate->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->admin->public_id,
            'payload' => ['email' => $event->admin->email],
            'ip_address' => $event->ip,
        ]);
    }
}
