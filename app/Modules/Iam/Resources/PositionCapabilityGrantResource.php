<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionCapabilityGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position_id' => $this->position?->public_id,
            'capability' => $this->whenLoaded('capability', function () {
                return [
                    'public_id' => $this->capability->public_id,
                    'key' => $this->capability->key,
                    'name_ar' => $this->capability->name_ar,
                ];
            }),
            'scope_type' => $this->scope_type->value,
            'scope_department' => $this->whenLoaded('scopeDepartment', function () {
                if ($this->scopeDepartment === null) {
                    return null;
                }

                return [
                    'public_id' => $this->scopeDepartment->public_id,
                    'name_ar' => $this->scopeDepartment->name_ar,
                ];
            }),
            'granted_by' => $this->grantedBy?->public_id,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
