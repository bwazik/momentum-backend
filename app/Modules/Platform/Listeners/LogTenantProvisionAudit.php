<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Modules\Platform\Models\AuditEvent;

class LogTenantProvisionAudit
{
    public function handle(TenantProvisioned $event): void
    {
        AuditEvent::create([
            'user_id' => $event->adminUserId,
            'action' => AuditAction::TenantCreate->value,
            'entity_type' => 'tenant',
            'entity_id' => $event->tenant->public_id,
            'payload' => ['slug' => $event->tenant->slug, 'name' => $event->tenant->name_en],
            'ip_address' => $event->ip,
        ]);
    }
}
