<?php

namespace App\Modules\FollowUp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BottleneckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage_type' => $this['stage_type'],
            'department' => $this['department'],
            'overdue_count' => $this['overdue_count'],
            'at_risk_count' => $this['at_risk_count'],
            'score' => $this['score'],
            'average_time_at_stage_seconds' => $this['average_time_at_stage_seconds'],
        ];
    }
}
