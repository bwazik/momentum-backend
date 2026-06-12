<?php

namespace App\Modules\Task\Resources;

use App\Modules\Blueprint\Resources\BlueprintResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'blueprint' => new BlueprintResource($this->whenLoaded('blueprint')),
            'priority' => new TaskPriorityResource($this->whenLoaded('priority')),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en ?? $this->title_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?? $this->description_ar,
            'classification_level' => $this->classification_level,
            'status' => $this->status,
            'initiator_id' => $this->initiator?->public_id,
            'due_date' => $this->due_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'resumed_at' => $this->resumed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'stages' => TaskStageInstanceResource::collection($this->whenLoaded('stageInstances')),
        ];
    }
}
