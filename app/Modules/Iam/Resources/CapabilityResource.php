<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CapabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'key' => $this->key,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'description' => $this->description,
            'is_system_defined' => $this->is_system_defined,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
