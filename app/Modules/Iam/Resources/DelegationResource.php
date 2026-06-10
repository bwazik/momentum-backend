<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DelegationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'delegator' => $this->whenLoaded('delegator', function () {
                return [
                    'public_id' => $this->delegator->public_id,
                    'name_ar' => $this->delegator->name_ar,
                    'name_en' => $this->delegator->name_en ?? $this->delegator->name_ar,
                ];
            }),
            'delegate' => $this->whenLoaded('delegate', function () {
                return [
                    'public_id' => $this->delegate->public_id,
                    'name_ar' => $this->delegate->name_ar,
                    'name_en' => $this->delegate->name_en ?? $this->delegate->name_ar,
                ];
            }),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'scope_type' => $this->scope_type->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
