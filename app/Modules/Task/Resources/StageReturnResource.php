<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'blueprint_stage' => [
                'public_id' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->public_id),
                'name_ar' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_ar),
                'name_en' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_en),
            ],
            'sequence_order' => $this->sequence_order,
            'return_reason' => $this->return_reason,
            'exited_at' => $this->exited_at?->toIso8601String(),
            'returned_by' => $this->whenLoaded('assignments', function () {
                $returner = $this->assignments->first();

                return $returner ? [
                    'user_id' => $returner->user?->public_id,
                    'user_name_ar' => $returner->user?->name_ar,
                    'user_name_en' => $returner->user?->name_en,
                ] : null;
            }),
        ];
    }
}
