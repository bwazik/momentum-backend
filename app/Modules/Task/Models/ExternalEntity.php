<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Modules\Task\Enums\ExternalEntityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_ar', 'name_en', 'entity_type', 'is_active'])]
class ExternalEntity extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'entity_type' => ExternalEntityType::class,
            'is_active' => 'boolean',
        ];
    }

    public function taskExternalReferences(): HasMany
    {
        return $this->hasMany(TaskExternalReference::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
