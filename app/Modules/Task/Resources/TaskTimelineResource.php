<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'type' => $data['type'],
            'timestamp' => $data['timestamp']?->toIso8601String(),
            'stage_name_ar' => $data['stage_name_ar'] ?? null,
            'stage_name_en' => $data['stage_name_en'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'user_name_ar' => $data['user_name_ar'] ?? null,
            'user_name_en' => $data['user_name_en'] ?? null,
            'return_reason' => $data['return_reason'] ?? null,
            'reassignment_reason' => $data['reassignment_reason'] ?? null,
            'completion_note' => $data['completion_note'] ?? null,
            'status' => $data['status'] ?? null,
            'sequence_order' => $data['sequence_order'] ?? null,
        ];
    }
}
