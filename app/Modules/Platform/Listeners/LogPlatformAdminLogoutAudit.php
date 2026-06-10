<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminLoggedOut;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminLogoutAudit
{
    public function handle(PlatformAdminLoggedOut $event): void
    {
        AuditEvent::create([
            'user_id' => $event->user->id,
            'action' => AuditAction::PlatformLogout->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->user->public_id,
            'payload' => ['all_devices' => $event->allDevices],
            'ip_address' => $event->ip,
        ]);
    }
}
