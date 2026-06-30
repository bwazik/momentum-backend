<?php

namespace App\Modules\Audit\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeSensitive = $request->route()?->getName() !== 'audit.my-activity';

        return [
            'public_id' => $this->public_id,
            'event_type' => $this->event_type,
            'entity_type' => $this->entity_type?->name(),
            'entity_id' => $this->entity_public_id,
            'root_entity_type' => $this->root_entity_type?->name(),
            'root_entity_id' => $this->root_entity_public_id,
            'performed_by' => $this->user ? [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en,
            ] : null,
            'ip_address' => $includeSensitive ? $this->ip_address : null,
            'user_agent' => $includeSensitive ? $this->user_agent : null,
            'impersonated_by_public_id' => $this->impersonated_by_public_id,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
