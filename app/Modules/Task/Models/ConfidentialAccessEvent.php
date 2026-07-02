<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfidentialAccessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id', 'user_id', 'access_type', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'access_type' => ConfidentialAccessEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
