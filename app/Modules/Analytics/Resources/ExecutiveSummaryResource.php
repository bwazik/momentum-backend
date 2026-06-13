<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecutiveSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active' => $this->resource['active'],
            'overdue' => $this->resource['overdue'],
            'at_risk' => $this->resource['at_risk'],
            'suspended' => $this->resource['suspended'],
            'completed' => $this->resource['completed'],
            'cancelled' => $this->resource['cancelled'],
            'completion_rate' => $this->resource['completion_rate'],
        ];
    }
}
