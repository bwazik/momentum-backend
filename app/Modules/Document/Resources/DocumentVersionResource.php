<?php

namespace App\Modules\Document\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'version_number' => $this->version_number,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploader' => [
                'public_id' => $this->uploader?->public_id,
                'name_ar' => $this->uploader?->name_ar,
                'name_en' => $this->uploader?->name_en,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
