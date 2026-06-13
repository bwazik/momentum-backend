<?php

namespace App\Modules\Tracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EscalationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'stage_instance_id' => $this->stageInstance?->public_id,
            'sub_stage_instance_id' => $this->subStageInstance?->public_id,
            'sla_timer' => $this->slaTimerInstance ? [
                'public_id' => $this->slaTimerInstance->public_id,
                'status' => $this->slaTimerInstance->status,
                'started_at' => $this->slaTimerInstance->started_at?->toIso8601String(),
                'deadline_at' => $this->slaTimerInstance->deadline_at?->toIso8601String(),
                'warning_at' => $this->slaTimerInstance->warning_at?->toIso8601String(),
            ] : null,
            'escalation_type' => $this->escalation_type,
            'escalated_to_user' => [
                'public_id' => $this->escalatedToUser?->public_id,
                'name_ar' => $this->escalatedToUser?->name_ar,
                'name_en' => $this->escalatedToUser?->name_en ?? $this->escalatedToUser?->name_ar,
            ],
            'escalated_to_position' => $this->escalatedToPosition ? [
                'public_id' => $this->escalatedToPosition->public_id,
                'name_ar' => $this->escalatedToPosition->name_ar,
                'name_en' => $this->escalatedToPosition->name_en ?? $this->escalatedToPosition->name_ar,
            ] : null,
            'escalated_by_user' => $this->escalatedByUser ? [
                'public_id' => $this->escalatedByUser->public_id,
                'name_ar' => $this->escalatedByUser->name_ar,
                'name_en' => $this->escalatedByUser->name_en ?? $this->escalatedByUser->name_ar,
            ] : null,
            'reason' => $this->reason,
            'status' => $this->status,
            'resolution_note' => $this->resolution_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
