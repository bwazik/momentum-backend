<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidentialParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en ?? $this->user->name_ar,
            ],
            'added_by' => [
                'public_id' => $this->addedBy->public_id,
                'name_ar' => $this->addedBy->name_ar,
                'name_en' => $this->addedBy->name_en ?? $this->addedBy->name_ar,
            ],
            'added_at' => $this->added_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
        ];
    }
}
