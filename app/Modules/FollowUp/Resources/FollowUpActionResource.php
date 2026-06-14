<?php

namespace App\Modules\FollowUp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'action_type' => $this->action_type->name,
            'note_ar' => $this->note_ar,
            'note_en' => $this->note_en ?? $this->note_ar,
            'contact_name' => $this->contact_name,
            'created_by' => $this->user ? [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
