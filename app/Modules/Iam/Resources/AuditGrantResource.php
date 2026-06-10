<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'external_auditor' => $this->whenLoaded('auditor', fn () => [
                'public_id' => $this->auditor->public_id,
                'name_ar' => $this->auditor->name_ar,
                'name_en' => $this->auditor->name_en ?? $this->auditor->name_ar,
            ]),
            'granted_by' => $this->grantedBy?->public_id,
            'date_range_start' => $this->date_range_start?->toDateString(),
            'date_range_end' => $this->date_range_end?->toDateString(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'public_id' => $this->department->public_id,
                'name_ar' => $this->department->name_ar,
            ] : null),
            'granted_at' => $this->granted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
