<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'blueprint_id', 'priority_id', 'title_ar', 'title_en',
    'description_ar', 'description_en', 'classification_level',
    'initiator_user_id', 'status', 'due_date', 'display_id',
    'launched_at', 'suspended_at', 'suspension_reason',
    'resumed_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
    'archived_at', 'archived_by_user_id',
])]
class Task extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::created(function (Task $task) {
            if (! $task->display_id) {
                $year = $task->created_at->format('Y');
                $seq = str_pad((string) $task->id, 4, '0', STR_PAD_LEFT);
                $task->updateQuietly(['display_id' => "T-{$year}-{$seq}"]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'classification_level' => ClassificationLevel::class,
            'due_date' => 'date',
            'launched_at' => 'datetime',
            'suspended_at' => 'datetime',
            'resumed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TaskPriority::class, 'priority_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    public function stageInstances(): HasMany
    {
        return $this->hasMany(TaskStageInstance::class)->orderBy('sequence_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskStageAssignment::class);
    }

    public function isDraft(): bool
    {
        return $this->status === TaskStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === TaskStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === TaskStatus::Suspended;
    }
}
