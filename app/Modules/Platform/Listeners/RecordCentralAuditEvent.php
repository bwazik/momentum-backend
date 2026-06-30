<?php

namespace App\Modules\Platform\Listeners;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Platform\Models\AuditEvent;
use Illuminate\Support\Facades\Log;

class RecordCentralAuditEvent
{
    public function handle(object $event): void
    {
        if (! $event instanceof ProvidesAuditData) {
            return;
        }

        try {
            $data = $event->auditData();

            AuditEvent::create([
                'event_type' => $data->eventType,
                'action' => $data->eventType,
                'entity_type_int' => $data->entityType,
                'entity_type' => $data->entityType->name(),
                'entity_id' => (string) $data->entityId,
                'entity_public_id' => $data->entityPublicId,
                'root_entity_type' => $data->rootEntityType,
                'root_entity_id' => $data->rootEntityId,
                'root_entity_public_id' => $data->rootEntityPublicId,
                'user_id' => $data->user?->id,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => $data->payload,
                'impersonated_by_public_id' => $data->payload['impersonated_by_public_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Failed to record central audit event', [
                'action' => 'audit.record_central',
                'event_class' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
