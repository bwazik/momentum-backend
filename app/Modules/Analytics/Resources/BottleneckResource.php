<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BottleneckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage_type' => $this->resource['stage_type'],
            'department' => $this->resource['department'],
            'overdue_count' => $this->resource['overdue_count'],
            'at_risk_count' => $this->resource['at_risk_count'],
            'score' => $this->resource['score'],
            'average_time_at_stage_seconds' => $this->resource['average_time_at_stage_seconds'],
        ];
    }
}
