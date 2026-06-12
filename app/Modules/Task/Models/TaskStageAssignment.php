<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\AssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'user_id',
    'position_id', 'delegated_from_user_id', 'assignment_role',
    'is_completed', 'assigned_at', 'completed_at', 'completion_note',
    'reassigned_at', 'reassigned_by_user_id', 'reassignment_reason',
])]
class TaskStageAssignment extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'assignment_role' => AssignmentRole::class,
            'is_completed' => 'boolean',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'reassigned_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function stageInstance(): BelongsTo
    {
        return $this->belongsTo(TaskStageInstance::class, 'stage_instance_id');
    }

    public function subStageInstance(): BelongsTo
    {
        return $this->belongsTo(TaskSubStageInstance::class, 'sub_stage_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function delegatedFromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_from_user_id');
    }

    public function reassignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reassigned_by_user_id');
    }
}
