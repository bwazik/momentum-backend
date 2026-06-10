<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\PlatformAdminLoggedIn;
use App\Modules\Platform\Models\AuditEvent;

class LogPlatformAdminLoginAudit
{
    public function handle(PlatformAdminLoggedIn $event): void
    {
        AuditEvent::create([
            'user_id' => $event->user->id,
            'action' => AuditAction::PlatformLogin->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $event->user->public_id,
            'payload' => ['ip_address' => $event->ip],
            'ip_address' => $event->ip,
        ]);
    }
}
