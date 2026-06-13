<?php

namespace App\Modules\Tracking\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'sla_policy_id',
    'working_calendar_id', 'started_at', 'deadline_at', 'warning_at',
    'paused_at', 'elapsed_before_pause', 'completed_at', 'status',
])]
class SlaTimerInstance extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SlaTimerStatus::class,
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'warning_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'elapsed_before_pause' => 'integer',
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

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function workingCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class, 'sla_timer_instance_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            SlaTimerStatus::Running->value,
            SlaTimerStatus::Warning->value,
        ]);
    }

    public function scopeForTask($query, int $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeDueWarning($query)
    {
        return $query->where('status', SlaTimerStatus::Running->value)
            ->whereNotNull('warning_at')
            ->where('warning_at', '<=', now());
    }

    public function scopeDueBreach($query)
    {
        return $query->whereIn('status', [
            SlaTimerStatus::Running->value,
            SlaTimerStatus::Warning->value,
        ])->where('deadline_at', '<=', now());
    }
}
