<?php

namespace App\Modules\Iam\Resources;

use App\Enums\ScopeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EffectiveCapabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'capability_key' => $this->capability_key,
            'source' => $this->source,
            'scope_type' => $this->scope_type instanceof ScopeType ? $this->scope_type->value : $this->scope_type,
            'scope_department_id' => $this->scope_department_id,
        ];
    }
}
