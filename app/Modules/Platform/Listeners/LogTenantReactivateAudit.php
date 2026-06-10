<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\TenantReactivated;
use App\Modules\Platform\Models\AuditEvent;

class LogTenantReactivateAudit
{
    public function handle(TenantReactivated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->adminUserId,
            'action' => AuditAction::TenantReactivate->value,
            'entity_type' => 'tenant',
            'entity_id' => $event->tenant->public_id,
            'payload' => ['slug' => $event->tenant->slug],
            'ip_address' => $event->ip,
        ]);
    }
}
