<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfidentialGovernanceParticipant extends TenantModel
{
    public $timestamps = false;

    protected $fillable = [
        'public_id', 'position_id', 'scope_type', 'scope_department_id',
        'blueprint_category_id', 'applies_to_classification_level',
        'created_by_user_id', 'created_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'applies_to_classification_level' => ClassificationLevel::class,
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function blueprintCategory(): BelongsTo
    {
        return $this->belongsTo(BlueprintCategory::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
