<?php

namespace App\Modules\Iam\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->resource->public_id,
            'name_ar' => $this->resource->name_ar,
            'name_en' => $this->resource->name_en ?? $this->resource->name_ar,
            'email' => $this->resource->email,
            'account_type' => $this->resource->account_type->value,
        ];
    }
}
