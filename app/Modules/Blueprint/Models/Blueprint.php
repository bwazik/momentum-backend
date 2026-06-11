<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Blueprint\Enums\BlueprintScope;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

#[Fillable(['category_id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'scope', 'department_id', 'is_locked', 'is_active', 'created_by_user_id'])]
class Blueprint extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
            'scope' => BlueprintScope::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlueprintCategory::class, 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(BlueprintStage::class)->orderBy('sequence_order');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(BlueprintTransition::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function hasTasks(): bool
    {
        if (! Schema::hasTable('tasks')) {
            return false;
        }

        return $this->tasks()->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
