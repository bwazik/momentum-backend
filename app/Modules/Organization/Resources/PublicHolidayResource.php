<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'holiday_date' => $this->holiday_date?->toDateString(),
            'is_recurring' => $this->is_recurring,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
