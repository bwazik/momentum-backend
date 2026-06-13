<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $activeStage = $task->stageInstances?->first();
        $currentStep = $activeStage?->subStageInstances?->first() ?? $activeStage;

        return [
            'task_public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en,
            'status' => $task->status?->value,
            'priority_public_id' => $task->priority?->public_id,
            'current_stage_name_ar' => $currentStep?->blueprintStage?->name_ar ?? $currentStep?->blueprintSubStage?->name_ar,
            'current_stage_name_en' => $currentStep?->blueprintStage?->name_en ?? $currentStep?->blueprintSubStage?->name_en,
            'owning_department_public_id' => $currentStep?->owningDepartment?->public_id,
            'sla_health' => 'none',
            'created_at' => $task->created_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
