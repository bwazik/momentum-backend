<?php

namespace App\Modules\Task\Models;

use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'task_id', 'parent_stage_instance_id', 'blueprint_sub_stage_id', 'sequence_order',
    'owning_department_id', 'is_required', 'completion_rule', 'status',
    'entered_at', 'exited_at', 'completion_note',
])]
class TaskSubStageInstance extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => SubStageInstanceStatus::class,
            'completion_rule' => CompletionRule::class,
            'is_required' => 'boolean',
            'sequence_order' => 'integer',
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function parentStageInstance(): BelongsTo
    {
        return $this->belongsTo(TaskStageInstance::class, 'parent_stage_instance_id');
    }

    public function blueprintSubStage(): BelongsTo
    {
        return $this->belongsTo(BlueprintSubStage::class, 'blueprint_sub_stage_id');
    }

    public function owningDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owning_department_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskStageAssignment::class, 'sub_stage_instance_id');
    }
}
