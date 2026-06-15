<?php

namespace App\Modules\Search\Models;

use App\Models\User;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'task_id', 'activity_type', 'occurred_at'])]
class UserRecentActivity extends Model
{
    protected $table = 'user_recent_activity';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'activity_type' => SearchActivityType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
