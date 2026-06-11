<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Enums\SlaUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_en', 'name_ar', 'sla_value', 'sla_unit', 'warning_threshold_percentage', 'is_active'])]
class SlaPolicy extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'sla_value' => 'integer',
            'sla_unit' => SlaUnit::class,
            'warning_threshold_percentage' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(BlueprintStage::class, 'sla_policy_id');
    }

    public function subStages(): HasMany
    {
        return $this->hasMany(BlueprintSubStage::class, 'sla_policy_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
