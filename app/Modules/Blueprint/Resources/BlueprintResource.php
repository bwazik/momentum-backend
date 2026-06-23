<?php

namespace App\Modules\Blueprint\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'category' => new BlueprintCategoryResource($this->whenLoaded('category')),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?? $this->description_ar,
            'scope' => $this->scope->apiValue(),
            'department_id' => $this->department?->public_id,
            'is_locked' => $this->is_locked,
            'is_active' => $this->is_active,
            'stages_count' => $this->when(! $this->relationLoaded('stages'), $this->stages_count ?? 0),
            'stages' => BlueprintStageResource::collection($this->whenLoaded('stages')),
            'transitions' => BlueprintTransitionResource::collection($this->whenLoaded('transitions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
