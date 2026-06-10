<?php

namespace App\Modules\Platform\Listeners;

use App\Enums\AuditAction;
use App\Modules\Platform\Events\ImpersonationStarted;
use App\Modules\Platform\Models\AuditEvent;

class LogImpersonationStartAudit
{
    public function handle(ImpersonationStarted $event): void
    {
        AuditEvent::create([
            'user_id' => $event->impersonator->id,
            'action' => AuditAction::ImpersonationStart->value,
            'entity_type' => 'impersonation',
            'entity_id' => $event->targetUser->public_id,
            'payload' => [
                'tenant_slug' => $event->tenantSlug,
                'tenant_public_id' => $event->tenantPublicId,
                'impersonated_user_public_id' => $event->targetUser->public_id,
            ],
            'ip_address' => $event->ip,
        ]);
    }
}
