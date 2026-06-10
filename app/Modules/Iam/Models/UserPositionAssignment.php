<?php

namespace App\Modules\Iam\Models;

use App\Models\User;
use App\Modules\Organization\Models\Position;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'position_id', 'started_at', 'ended_at', 'is_primary'])]
class UserPositionAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true)->whereNull('ended_at');
    }
}
