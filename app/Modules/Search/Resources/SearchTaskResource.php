<?php

namespace App\Modules\Search\Resources;

use App\Modules\Task\Enums\StageInstanceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $activeStage = $task->stageInstances
            ->first(fn ($s) => $s->status === StageInstanceStatus::Active);

        return [
            'public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en ?? $task->title_ar,
            'status' => $task->status,
            'priority' => $task->priority ? [
                'public_id' => $task->priority->public_id,
                'name_ar' => $task->priority->name_ar,
                'name_en' => $task->priority->name_en,
            ] : null,
            'classification_level' => $task->classification_level,
            'current_stage' => $activeStage ? [
                'public_id' => $activeStage->blueprintStage?->public_id,
                'name_ar' => $activeStage->blueprintStage?->name_ar,
                'name_en' => $activeStage->blueprintStage?->name_en,
                'stage_type' => $activeStage->blueprintStage?->stageType ? [
                    'public_id' => $activeStage->blueprintStage->stageType->public_id,
                    'name_ar' => $activeStage->blueprintStage->stageType->name_ar,
                    'name_en' => $activeStage->blueprintStage->stageType->name_en,
                ] : null,
            ] : null,
            'department' => $activeStage?->owningDepartment ? [
                'public_id' => $activeStage->owningDepartment->public_id,
                'name_ar' => $activeStage->owningDepartment->name_ar,
                'name_en' => $activeStage->owningDepartment->name_en,
            ] : null,
            'blueprint_category' => $task->blueprint?->category ? [
                'public_id' => $task->blueprint->category->public_id,
                'name_ar' => $task->blueprint->category->name_ar,
                'name_en' => $task->blueprint->category->name_en,
            ] : null,
            'due_date' => $task->due_date?->toDateString(),
            'created_at' => $task->created_at?->toIso8601String(),
            'snippet_ar' => $task->snippet_ar ?: null,
            'snippet_en' => $task->snippet_en ?: null,
        ];
    }
}
