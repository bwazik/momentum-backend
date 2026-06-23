<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_ar', 'name_en', 'severity_rank', 'color_code', 'is_default', 'is_active', 'display_order'])]
class TaskPriority extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'severity_rank' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function apiValue(): string
    {
        return match ($this->severity_rank) {
            1 => 'critical',
            2 => 'urgent',
            3 => 'routine',
            default => 'routine',
        };
    }
}
