<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['department_id', 'title_ar', 'title_en', 'reports_to_position_id', 'authority_grade_id', 'is_department_head', 'is_active'])]
class Position extends TenantModel
{
    use HasFactory, SoftDeletes;

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_position_id');
    }

    public function authorityGrade(): BelongsTo
    {
        return $this->belongsTo(AuthorityGrade::class);
    }

    protected function casts(): array
    {
        return [
            'is_department_head' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    // TODO: Uncomment when IAM module is built (Spec 003)
    // public function currentOccupant(): HasOne
    // {
    //     return $this->hasOne(\App\Modules\Iam\Models\UserPositionAssignment::class, 'position_id')
    //         ->where('is_primary', true)
    //         ->whereNull('ended_at');
    // }
}
