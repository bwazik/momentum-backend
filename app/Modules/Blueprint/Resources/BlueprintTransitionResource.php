<?php

namespace App\Modules\Blueprint\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'blueprint_id' => $this->blueprint->public_id,
            'from_stage_id' => $this->fromStage?->public_id,
            'to_stage_id' => $this->toStage?->public_id,
            'transition_type' => $this->transition_type,
            'return_reason_required' => $this->return_reason_required,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
