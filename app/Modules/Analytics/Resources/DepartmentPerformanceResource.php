<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentPerformanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'department_public_id' => $this->resource['department_public_id'],
            'active_tasks' => $this->resource['active_tasks'],
            'overdue_tasks' => $this->resource['overdue_tasks'],
            'at_risk_tasks' => $this->resource['at_risk_tasks'],
            'average_stage_delay_seconds' => $this->resource['average_stage_delay_seconds'],
        ];
    }
}
