<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidentialGovernanceParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'position' => [
                'public_id' => $this->position?->public_id,
                'title_ar' => $this->position?->title_ar,
                'title_en' => $this->position?->title_en ?? $this->position?->title_ar,
            ],
            'scope_type' => $this->scope_type->value,
            'scope_department' => $this->whenLoaded('scopeDepartment', function () {
                return [
                    'public_id' => $this->scopeDepartment?->public_id,
                    'name_ar' => $this->scopeDepartment?->name_ar,
                    'name_en' => $this->scopeDepartment?->name_en ?? $this->scopeDepartment?->name_ar,
                ];
            }),
            'blueprint_category' => $this->whenLoaded('blueprintCategory', function () {
                return [
                    'public_id' => $this->blueprintCategory?->public_id,
                    'name_ar' => $this->blueprintCategory?->name_ar,
                    'name_en' => $this->blueprintCategory?->name_en ?? $this->blueprintCategory?->name_ar,
                ];
            }),
            'applies_to_classification_level' => $this->applies_to_classification_level->value,
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'public_id' => $this->createdBy?->public_id,
                    'name_ar' => $this->createdBy?->name_ar,
                    'name_en' => $this->createdBy?->name_en ?? $this->createdBy?->name_ar,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
