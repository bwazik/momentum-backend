<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\TenantUpdated;
use App\Modules\Platform\Models\AuditEvent;

class LogTenantUpdateAudit
{
    public function handle(TenantUpdated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->adminUserId,
            'action' => AuditAction::TenantUpdate->value,
            'entity_type' => 'tenant',
            'entity_id' => $event->tenant->public_id,
            'payload' => $event->data,
            'ip_address' => $event->ip,
        ]);
    }
}
