<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_en', 'name_ar', 'is_system_default', 'is_active', 'display_order'])]
class StageType extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(BlueprintStage::class, 'stage_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
