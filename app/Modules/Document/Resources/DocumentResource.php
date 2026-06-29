<?php

namespace App\Modules\Document\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'mime_category' => $this->mimeCategory()->name,
            'size_bytes' => $this->size_bytes,
            'version_number' => $this->version_number,
            'description' => $this->description,
            'uploader' => [
                'public_id' => $this->uploader?->public_id,
                'name_ar' => $this->uploader?->name_ar,
                'name_en' => $this->uploader?->name_en,
            ],
            'download_url' => route('documents.download', $this->public_id),
            'preview_url' => $this->mimeCategory()->supportsPreview()
                ? route('documents.preview', $this->public_id)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
