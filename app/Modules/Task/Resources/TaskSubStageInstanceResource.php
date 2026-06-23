<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskSubStageInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'instance_id' => $this->id,
            'blueprint_sub_stage' => [
                'public_id' => $this->whenLoaded('blueprintSubStage', fn () => $this->blueprintSubStage->public_id),
                'name_ar' => $this->whenLoaded('blueprintSubStage', fn () => $this->blueprintSubStage->name_ar),
                'name_en' => $this->whenLoaded('blueprintSubStage', fn () => $this->blueprintSubStage->name_en),
            ],
            'sequence_order' => $this->sequence_order,
            'owning_department_id' => $this->owning_department_id,
            'is_required' => $this->is_required,
            'completion_rule' => $this->completion_rule?->apiValue(),
            'status' => $this->status?->apiValue(),
            'entered_at' => $this->entered_at?->toIso8601String(),
            'exited_at' => $this->exited_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
            'assignments' => TaskStageAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
