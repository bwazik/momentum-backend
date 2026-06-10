<?php

namespace App\Modules\Iam\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name_ar', 'name_en', 'description', 'is_system_defined'])]
class Capability extends TenantModel
{
    public function positionCapabilityGrants(): HasMany
    {
        return $this->hasMany(PositionCapabilityGrant::class);
    }

    public function userCapabilityGrants(): HasMany
    {
        return $this->hasMany(UserCapabilityGrant::class);
    }

    protected function casts(): array
    {
        return [
            'is_system_defined' => 'boolean',
        ];
    }
}
