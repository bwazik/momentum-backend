<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\TenantSuspended;
use App\Modules\Platform\Models\AuditEvent;

class LogTenantSuspendAudit
{
    public function handle(TenantSuspended $event): void
    {
        AuditEvent::create([
            'user_id' => $event->adminUserId,
            'action' => AuditAction::TenantSuspend->value,
            'entity_type' => 'tenant',
            'entity_id' => $event->tenant->public_id,
            'payload' => ['slug' => $event->tenant->slug],
            'ip_address' => $event->ip,
        ]);
    }
}
