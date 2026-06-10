<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitoringScopeGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user?->public_id,
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
            'blueprint_category_id' => $this->blueprint_category_id,
            'granted_by' => $this->grantedBy?->public_id,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
