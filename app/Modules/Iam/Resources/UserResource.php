<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'employee_id' => $this->employee_id,
            'account_type' => $this->account_type->value,
            'preferred_language' => $this->preferred_language,
            'is_active' => $this->is_active,
            'is_out_of_office' => $this->is_out_of_office,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
