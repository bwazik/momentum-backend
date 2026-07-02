<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidentialAccessEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en ?? $this->user->name_ar,
            ],
            'access_type' => $this->access_type->auditEventType(),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
