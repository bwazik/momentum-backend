<?php

namespace App\Modules\Tracking\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'sla_timer_instance_id',
    'escalation_type', 'escalated_to_user_id', 'escalated_to_position_id',
    'escalated_by_user_id', 'reason', 'status', 'resolution_note', 'resolved_at',
])]
class Escalation extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'escalation_type' => EscalationType::class,
            'status' => EscalationStatus::class,
            'resolved_at' => 'datetime',
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

    public function slaTimerInstance(): BelongsTo
    {
        return $this->belongsTo(SlaTimerInstance::class, 'sla_timer_instance_id');
    }

    public function escalatedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }

    public function escalatedToPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'escalated_to_position_id');
    }

    public function escalatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', EscalationStatus::Open->value);
    }
}
