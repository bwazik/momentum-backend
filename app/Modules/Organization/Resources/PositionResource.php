<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'department' => [
                'public_id' => $this->department?->public_id,
                'name_ar' => $this->department?->name_ar,
            ],
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en ?? $this->title_ar,
            'reports_to_position_id' => $this->reportsTo?->public_id,
            'authority_grade' => [
                'public_id' => $this->authorityGrade?->public_id,
                'rank' => $this->authorityGrade?->rank,
                'name_ar' => $this->authorityGrade?->name_ar,
            ],
            'is_department_head' => $this->is_department_head,
            'is_active' => $this->is_active,
            'current_occupant' => $this->whenLoaded('currentOccupant', function () {
                $assignment = $this->currentOccupant;

                if ($assignment && $assignment->user) {
                    return [
                        'public_id' => $assignment->user->public_id,
                        'name_ar' => $assignment->user->name_ar,
                        'name_en' => $assignment->user->name_en ?? $assignment->user->name_ar,
                    ];
                }

                return null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
