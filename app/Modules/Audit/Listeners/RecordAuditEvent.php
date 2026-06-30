<?php

namespace App\Modules\Audit\Listeners;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Events\AuditEventRecorded;
use App\Modules\Audit\Models\AuditEvent;
use Illuminate\Support\Facades\Log;

class RecordAuditEvent
{
    public function handle(object $event): void
    {
        if (! $event instanceof ProvidesAuditData) {
            return;
        }

        try {
            $data = $event->auditData();

            $auditEvent = AuditEvent::create([
                'event_type' => $data->eventType,
                'entity_type' => $data->entityType,
                'entity_id' => $data->entityId,
                'entity_public_id' => $data->entityPublicId,
                'root_entity_type' => $data->rootEntityType,
                'root_entity_id' => $data->rootEntityId,
                'root_entity_public_id' => $data->rootEntityPublicId,
                'user_id' => $this->resolveUserId($data->user ?? request()->user()),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'payload' => $data->payload,
                'impersonated_by_public_id' => $this->resolveImpersonator(),
            ]);

            event(new AuditEventRecorded($auditEvent));
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to record audit event', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.record',
                'event_class' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveUserId(?User $user): ?int
    {
        return $user?->id;
    }

    private function resolveImpersonator(): ?string
    {
        $req = request();

        if (! $req) {
            return null;
        }

        $token = $req->user()?->currentAccessToken();

        if (! $token) {
            return null;
        }

        foreach ($token->abilities as $ability) {
            if (str_starts_with($ability, 'impersonated-by:')) {
                return substr($ability, strlen('impersonated-by:'));
            }
        }

        return null;
    }
}
