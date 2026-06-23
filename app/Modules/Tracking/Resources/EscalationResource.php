<?php

namespace App\Modules\Tracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EscalationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'task_display_id' => $this->task?->display_id,
            'stage_instance_id' => $this->stageInstance?->public_id,
            'sub_stage_instance_id' => $this->subStageInstance?->public_id,
            'escalation_type' => $this->escalation_type?->apiValue(),
            'escalated_to_user' => [
                'public_id' => $this->escalatedToUser?->public_id,
                'name_ar' => $this->escalatedToUser?->name_ar,
                'name_en' => $this->escalatedToUser?->name_en ?? $this->escalatedToUser?->name_ar,
            ],
            'escalated_by_user' => $this->escalatedByUser ? [
                'public_id' => $this->escalatedByUser->public_id,
                'name_ar' => $this->escalatedByUser->name_ar,
                'name_en' => $this->escalatedByUser->name_en ?? $this->escalatedByUser->name_ar,
            ] : null,
            'reason' => $this->reason,
            'status' => $this->status?->apiValue(),
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
