<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\TenantMigrated;
use App\Modules\Platform\Models\AuditEvent;

class LogTenantMigrateAudit
{
    public function handle(TenantMigrated $event): void
    {
        AuditEvent::create([
            'user_id' => $event->adminUserId,
            'action' => AuditAction::TenantRunMigrations->value,
            'entity_type' => 'tenant',
            'entity_id' => $event->tenant->public_id,
            'payload' => [
                'status' => $event->status,
                ...($event->error ? ['error' => $event->error] : []),
            ],
            'ip_address' => $event->ip,
        ]);
    }
}
