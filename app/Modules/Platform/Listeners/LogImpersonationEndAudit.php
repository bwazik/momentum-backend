<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\ImpersonationEnded;
use App\Modules\Platform\Models\AuditEvent;

class LogImpersonationEndAudit
{
    public function handle(ImpersonationEnded $event): void
    {
        AuditEvent::create([
            'user_id' => $event->impersonator->id,
            'action' => AuditAction::ImpersonationEnd->value,
            'entity_type' => 'impersonation',
            'entity_id' => $event->entityId,
            'payload' => ['tenant_public_id' => $event->tenantPublicId],
            'ip_address' => $event->ip,
        ]);
    }
}
