<?php

namespace App\Modules\Task\Models;

use App\Modules\Blueprint\Enums\CompletionRule;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\StageInstanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'task_id', 'blueprint_stage_id', 'sequence_order', 'owning_department_id',
    'completion_rule', 'status', 'entered_at', 'exited_at', 'completion_note', 'return_reason',
])]
class TaskStageInstance extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => StageInstanceStatus::class,
            'completion_rule' => CompletionRule::class,
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

    public function blueprintStage(): BelongsTo
    {
        return $this->belongsTo(BlueprintStage::class, 'blueprint_stage_id');
    }

    public function owningDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'owning_department_id');
    }

    public function subStageInstances(): HasMany
    {
        return $this->hasMany(TaskSubStageInstance::class, 'parent_stage_instance_id')->orderBy('sequence_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskStageAssignment::class, 'stage_instance_id');
    }
}
