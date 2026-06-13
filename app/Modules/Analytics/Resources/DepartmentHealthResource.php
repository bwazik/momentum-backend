<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentHealthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'department_public_id' => $this->resource['department_public_id'],
            'department_name_ar' => $this->resource['department_name_ar'],
            'department_name_en' => $this->resource['department_name_en'],
            'health' => $this->resource['health'],
            'health_label' => $this->resource['health_label'],
            'active_tasks' => $this->resource['active_tasks'],
            'overdue_tasks' => $this->resource['overdue_tasks'],
            'at_risk_tasks' => $this->resource['at_risk_tasks'],
        ];
    }
}
