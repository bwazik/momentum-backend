<?php

namespace App\Modules\Iam\Models;

use App\Models\User;
use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['external_auditor_user_id', 'granted_by_user_id', 'date_range_start', 'date_range_end', 'department_id', 'granted_at', 'revoked_at'])]
class AuditGrant extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_range_start' => 'date',
            'date_range_end' => 'date',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'external_auditor_user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
