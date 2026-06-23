<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskStageAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user?->public_id,
            'user_name_ar' => $this->user?->name_ar,
            'user_name_en' => $this->user?->name_en,
            'position_id' => $this->position?->public_id,
            'delegated_from_user_id' => $this->delegatedFromUser?->public_id,
            'assignment_role' => $this->assignment_role?->apiValue(),
            'is_completed' => $this->is_completed,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completion_note' => $this->completion_note,
            'reassigned_at' => $this->reassigned_at?->toIso8601String(),
            'reassigned_by_user_id' => $this->reassignedByUser?->public_id,
            'reassignment_reason' => $this->reassignment_reason,
        ];
    }
}
