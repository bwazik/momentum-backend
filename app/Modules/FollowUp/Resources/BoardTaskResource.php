<?php

namespace App\Modules\FollowUp\Resources;

use App\Modules\Task\Models\TaskSubStageInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $step = $task->_current_step;
        $stageInstance = $step instanceof TaskSubStageInstance
            ? $step->parentStageInstance
            : $step;
        $subStageInstance = $step instanceof TaskSubStageInstance ? $step : null;

        return [
            'public_id' => $task->public_id,
            'display_id' => $task->display_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en,
            'status' => $task->status->apiValue(),
            'priority' => $task->priority ? [
                'public_id' => $task->priority->public_id,
                'name_ar' => $task->priority->name_ar,
                'name_en' => $task->priority->name_en,
                'severity_rank' => strtolower($task->priority->name_en ?? $task->priority->name_ar),
                'color_code' => $task->priority->color_code,
            ] : null,
            'classification_level' => $task->classification_level?->apiValue(),
            'current_stage' => [
                'public_id' => $subStageInstance?->blueprintSubStage?->public_id ?? $stageInstance?->blueprintStage?->public_id,
                'name_ar' => $subStageInstance?->blueprintSubStage?->name_ar ?? $stageInstance?->blueprintStage?->name_ar,
                'name_en' => $subStageInstance?->blueprintSubStage?->name_en ?? $stageInstance?->blueprintStage?->name_en,
                'stage_type' => $stageInstance?->blueprintStage?->stageType ? [
                    'public_id' => $stageInstance->blueprintStage->stageType->public_id,
                    'name_ar' => $stageInstance->blueprintStage->stageType->name_ar,
                    'name_en' => $stageInstance->blueprintStage->stageType->name_en,
                ] : null,
            ],
            'current_assignees' => $task->_current_assignees->map(fn ($a) => [
                'public_id' => $a->user?->public_id,
                'name_ar' => $a->user?->name_ar,
                'name_en' => $a->user?->name_en,
                'position_public_id' => $a->position?->public_id,
            ])->filter(fn ($u) => $u['public_id'] !== null)->values(),
            'sla_health' => $task->_sla_health->name ?? 'green',
            'time_at_current_stage_seconds' => $task->_time_at_stage_seconds ?? 0,
            'department' => $step?->owningDepartment ? [
                'public_id' => $step->owningDepartment->public_id,
                'name_ar' => $step->owningDepartment->name_ar,
                'name_en' => $step->owningDepartment->name_en,
            ] : null,
            'blueprint_category' => $task->blueprint?->category ? [
                'public_id' => $task->blueprint->category->public_id,
                'name_ar' => $task->blueprint->category->name_ar,
                'name_en' => $task->blueprint->category->name_en,
            ] : null,
            'due_date' => $task->due_date?->toDateString(),
            'created_at' => $task->created_at?->toIso8601String(),
            'launched_at' => $task->launched_at?->toIso8601String(),
        ];
    }
}
