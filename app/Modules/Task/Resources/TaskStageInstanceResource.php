<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskStageInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'blueprint_stage' => [
                'public_id' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->public_id),
                'name_ar' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_ar),
                'name_en' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_en),
            ],
            'sequence_order' => $this->sequence_order,
            'owning_department_id' => $this->owning_department_id,
            'completion_rule' => $this->completion_rule,
            'status' => $this->status,
            'entered_at' => $this->entered_at?->toIso8601String(),
            'exited_at' => $this->exited_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
            'return_reason' => $this->return_reason,
            'sub_stages' => TaskSubStageInstanceResource::collection($this->whenLoaded('subStageInstances')),
            'assignments' => TaskStageAssignmentResource::collection($this->whenLoaded('assignments')),
        ];
    }
}
