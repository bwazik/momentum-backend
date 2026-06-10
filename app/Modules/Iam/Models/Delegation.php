<?php

namespace App\Modules\Iam\Models;

use App\Enums\DelegationScopeType;
use App\Models\User;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delegator_user_id',
    'delegate_user_id',
    'starts_at',
    'ends_at',
    'scope_type',
    'blueprint_category_id',
    'stage_type_id',
    'is_active',
])]
class Delegation extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'scope_type' => DelegationScopeType::class,
            'is_active' => 'boolean',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrentlyActive($query)
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }
}
