<?php

namespace App\Modules\Tracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaTimerInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'task_display_id' => $this->task?->display_id,
            'stage_instance_id' => $this->stageInstance?->id,
            'sub_stage_instance_id' => $this->subStageInstance?->id,
            'sla_policy' => [
                'public_id' => $this->slaPolicy?->public_id,
                'name_ar' => $this->slaPolicy?->name_ar,
                'name_en' => $this->slaPolicy?->name_en ?? $this->slaPolicy?->name_ar,
                'sla_value' => $this->slaPolicy?->sla_value,
                'sla_unit' => $this->slaPolicy?->sla_unit,
            ],
            'status' => $this->status?->apiValue(),
            'started_at' => $this->started_at?->toIso8601String(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'warning_at' => $this->warning_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'elapsed_before_pause' => $this->elapsed_before_pause,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
