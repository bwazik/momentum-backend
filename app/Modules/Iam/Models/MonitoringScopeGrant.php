<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'scope_type', 'scope_department_id', 'blueprint_category_id', 'granted_by_user_id', 'granted_at', 'revoked_at'])]
class MonitoringScopeGrant extends Model
{
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
