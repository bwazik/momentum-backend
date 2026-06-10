<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id ?? null,
            'position' => $this->whenLoaded('position', function () {
                return [
                    'public_id' => $this->position->public_id,
                    'title_ar' => $this->position->title_ar,
                    'title_en' => $this->position->title_en ?? $this->position->title_ar,
                    'department' => $this->whenLoaded('position.department', function () {
                        return [
                            'public_id' => $this->position->department->public_id,
                            'name_ar' => $this->position->department->name_ar,
                        ];
                    }),
                    'authority_grade' => $this->whenLoaded('position.authorityGrade', function () {
                        return [
                            'public_id' => $this->position->authorityGrade->public_id,
                            'rank' => $this->position->authorityGrade->rank,
                            'name_ar' => $this->position->authorityGrade->name_ar,
                        ];
                    }),
                ];
            }),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'is_primary' => $this->is_primary,
        ];
    }
}
