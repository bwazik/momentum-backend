<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMetricsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_public_id' => $this->resource['user_public_id'],
            'active_assignments' => $this->resource['active_assignments'],
            'overdue_assignments' => $this->resource['overdue_assignments'],
            'completed_stages' => $this->resource['completed_stages'],
        ];
    }
}
