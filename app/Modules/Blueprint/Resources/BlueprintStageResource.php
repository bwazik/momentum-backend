<?php

namespace App\Modules\Blueprint\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'blueprint_id' => $this->blueprint->public_id,
            'stage_type' => new StageTypeResource($this->whenLoaded('stageType')),
            'sla_policy' => new SlaPolicyResource($this->whenLoaded('slaPolicy')),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?? $this->description_ar,
            'sequence_order' => $this->sequence_order,
            'assignment_type' => $this->assignment_type,
            'assigned_position_id' => $this->assignedPosition?->public_id,
            'assigned_department_id' => $this->assignedDepartment?->public_id,
            'assignment_cardinality' => $this->assignment_cardinality,
            'completion_rule' => $this->completion_rule,
            'escalation_position_id' => $this->escalationPosition?->public_id,
            'sub_stages' => BlueprintSubStageResource::collection($this->whenLoaded('subStages')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
