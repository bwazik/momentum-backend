<?php

namespace App\Modules\Iam\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthTokenResource extends JsonResource
{
    public function __construct(
        User $resource,
        public string $token,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'public_id' => $this->resource->public_id,
                'name_ar' => $this->resource->name_ar,
                'name_en' => $this->resource->name_en ?? $this->resource->name_ar,
                'email' => $this->resource->email,
                'account_type' => $this->resource->account_type->value,
            ],
            'token' => $this->token,
        ];
    }
}
