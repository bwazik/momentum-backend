<?php

namespace App\Modules\Platform\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformAuthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new PlatformAdminResource($this->resource['user']),
            'token' => $this->resource['token'],
        ];
    }
}
