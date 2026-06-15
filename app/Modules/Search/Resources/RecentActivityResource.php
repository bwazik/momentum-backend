<?php

namespace App\Modules\Search\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecentActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        return [
            'public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en ?? $task->title_ar,
            'status' => $task->status,
            'activity_type' => $task->_activity_type->name,
            'occurred_at' => $task->_occurred_at,
        ];
    }
}
