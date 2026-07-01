<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskExternalReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reference_type' => $this->reference_type?->apiValue(),
            'reference_number' => $this->reference_number,
            'external_entity' => $this->when($this->relationLoaded('externalEntity') && $this->externalEntity !== null, fn () => new ExternalEntityResource($this->externalEntity)),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
