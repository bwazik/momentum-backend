<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgingReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $activeStage = $task->stageInstances->first();
        $currentStep = $activeStage?->subStageInstances->first() ?? $activeStage;

        return [
            'task_public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en,
            'priority' => $task->priority?->name_ar,
            'current_stage_name_ar' => $currentStep?->blueprintStage?->name_ar ?? $currentStep?->blueprintSubStage?->name_ar,
            'current_stage_name_en' => $currentStep?->blueprintStage?->name_en ?? $currentStep?->blueprintSubStage?->name_en,
            'active_assignees' => $currentStep?->assignments->map(fn ($a) => [
                'public_id' => $a->user?->public_id,
                'name_ar' => $a->user?->name_ar,
                'name_en' => $a->user?->name_en,
            ])->filter(fn ($u) => $u['public_id'] !== null)->values(),
            'sla_health' => $task->_sla_health ?? 'none',
            'created_at' => $task->created_at?->toIso8601String(),
            'entered_at' => $task->_step_entered_at ?? $currentStep?->entered_at?->toIso8601String(),
        ];
    }
}
