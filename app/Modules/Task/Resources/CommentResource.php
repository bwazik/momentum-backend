<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'author' => [
                'public_id' => $this->user?->public_id,
                'name_ar' => $this->user?->name_ar,
                'name_en' => $this->user?->name_en,
            ],
            'body' => $this->body,
            'parent_comment_id' => $this->parent?->public_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachment_count' => $this->whenCounted('documents'),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
        ];
    }
}
