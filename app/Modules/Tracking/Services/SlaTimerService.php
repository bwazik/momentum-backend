<?php

namespace App\Modules\Tracking\Services;

use App\Modules\Blueprint\Enums\SlaUnit;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use App\Modules\Tracking\Events\SlaTimerCompleted;
use App\Modules\Tracking\Events\SlaTimerPaused;
use App\Modules\Tracking\Events\SlaTimerResumed;
use App\Modules\Tracking\Events\SlaTimerStarted;
use App\Modules\Tracking\Models\SlaTimerInstance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SlaTimerService
{
    public function __construct(
        private WorkingDayCalculator $workingDayCalculator,
    ) {}

    public function createTimerForStage(TaskStageInstance $stageInstance): ?SlaTimerInstance
    {
        try {
            return DB::transaction(function () use ($stageInstance) {
                $blueprintStage = $stageInstance->blueprintStage()->with('slaPolicy')->first();

                if (! $blueprintStage->sla_policy_id) {
                    return null;
                }

                $existingTimer = SlaTimerInstance::where('stage_instance_id', $stageInstance->id)
                    ->active()
                    ->exists();

                if ($existingTimer) {
                    return null;
                }

                $slaPolicy = $blueprintStage->slaPolicy;
                $calendar = $this->resolveWorkingCalendar($stageInstance->owning_department_id);
                $startedAt = $stageInstance->entered_at ?? now();

                $deadlineAt = $this->calculateDeadline($calendar, $startedAt, $slaPolicy);
                $warningAt = $this->calculateWarning($startedAt, $deadlineAt, $slaPolicy->warning_threshold_percentage);

                $timer = SlaTimerInstance::create([
                    'task_id' => $stageInstance->task_id,
                    'stage_instance_id' => $stageInstance->id,
                    'sub_stage_instance_id' => null,
                    'sla_policy_id' => $slaPolicy->id,
                    'working_calendar_id' => $calendar->id,
                    'started_at' => $startedAt,
                    'deadline_at' => $deadlineAt,
                    'warning_at' => $warningAt,
                    'status' => SlaTimerStatus::Running,
                ]);

                event(new SlaTimerStarted($timer));

                return $timer;
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to create SLA timer for stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.create',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $stageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createTimerForSubStage(TaskSubStageInstance $subStageInstance): ?SlaTimerInstance
    {
        try {
            return DB::transaction(function () use ($subStageInstance) {
                $blueprintSubStage = $subStageInstance->blueprintSubStage()->with('slaPolicy')->first();

                if (! $blueprintSubStage->sla_policy_id) {
                    return null;
                }

                $existingTimer = SlaTimerInstance::where('sub_stage_instance_id', $subStageInstance->id)
                    ->active()
                    ->exists();

                if ($existingTimer) {
                    return null;
                }

                $slaPolicy = $blueprintSubStage->slaPolicy;
                $calendar = $this->resolveWorkingCalendar($subStageInstance->owning_department_id);
                $startedAt = $subStageInstance->entered_at ?? now();

                $deadlineAt = $this->calculateDeadline($calendar, $startedAt, $slaPolicy);
                $warningAt = $this->calculateWarning($startedAt, $deadlineAt, $slaPolicy->warning_threshold_percentage);

                $timer = SlaTimerInstance::create([
                    'task_id' => $subStageInstance->task_id,
                    'stage_instance_id' => null,
                    'sub_stage_instance_id' => $subStageInstance->id,
                    'sla_policy_id' => $slaPolicy->id,
                    'working_calendar_id' => $calendar->id,
                    'started_at' => $startedAt,
                    'deadline_at' => $deadlineAt,
                    'warning_at' => $warningAt,
                    'status' => SlaTimerStatus::Running,
                ]);

                event(new SlaTimerStarted($timer));

                return $timer;
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to create SLA timer for sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.create_sub_stage',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $subStageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function completeTimerForStage(TaskStageInstance $stageInstance): void
    {
        try {
            DB::transaction(function () use ($stageInstance) {
                $timer = SlaTimerInstance::where('stage_instance_id', $stageInstance->id)
                    ->whereIn('status', [
                        SlaTimerStatus::Running->value,
                        SlaTimerStatus::Warning->value,
                        SlaTimerStatus::Paused->value,
                    ])
                    ->first();

                if (! $timer) {
                    return;
                }

                $timer->update([
                    'status' => SlaTimerStatus::Completed,
                    'completed_at' => now(),
                ]);

                event(new SlaTimerCompleted($timer));
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to complete SLA timer for stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $stageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function completeTimerForSubStage(TaskSubStageInstance $subStageInstance): void
    {
        try {
            DB::transaction(function () use ($subStageInstance) {
                $timer = SlaTimerInstance::where('sub_stage_instance_id', $subStageInstance->id)
                    ->whereIn('status', [
                        SlaTimerStatus::Running->value,
                        SlaTimerStatus::Warning->value,
                        SlaTimerStatus::Paused->value,
                    ])
                    ->first();

                if (! $timer) {
                    return;
                }

                $timer->update([
                    'status' => SlaTimerStatus::Completed,
                    'completed_at' => now(),
                ]);

                event(new SlaTimerCompleted($timer));
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to complete SLA timer for sub-stage', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete_sub_stage',
                'entity_type' => 'task_sub_stage_instance',
                'entity_id' => $subStageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function completeAllTimersForTask(Task $task): void
    {
        try {
            DB::transaction(function () use ($task) {
                $timers = SlaTimerInstance::forTask($task->id)
                    ->whereIn('status', [
                        SlaTimerStatus::Running->value,
                        SlaTimerStatus::Warning->value,
                        SlaTimerStatus::Paused->value,
                    ])
                    ->get();

                foreach ($timers as $timer) {
                    $timer->update([
                        'status' => SlaTimerStatus::Completed,
                        'completed_at' => now(),
                    ]);

                    event(new SlaTimerCompleted($timer));
                }
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to complete all timers for task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.complete_all',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function pauseAllTimersForTask(Task $task): void
    {
        try {
            DB::transaction(function () use ($task) {
                $timers = SlaTimerInstance::forTask($task->id)->active()->lockForUpdate()->get();

                foreach ($timers as $timer) {
                    $calendar = $timer->workingCalendar;
                    $elapsedSeconds = $this->workingDayCalculator->workingSecondsBetween(
                        $calendar,
                        $timer->paused_at ? $timer->paused_at : $timer->started_at,
                        now()
                    );

                    $timer->update([
                        'status' => SlaTimerStatus::Paused,
                        'paused_at' => now(),
                        'elapsed_before_pause' => $timer->elapsed_before_pause + $elapsedSeconds,
                    ]);

                    event(new SlaTimerPaused($timer));
                }
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to pause timers for task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.pause_all',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function resumeAllTimersForTask(Task $task): void
    {
        try {
            DB::transaction(function () use ($task) {
                $timers = SlaTimerInstance::forTask($task->id)
                    ->where('status', SlaTimerStatus::Paused->value)
                    ->lockForUpdate()
                    ->get();

                foreach ($timers as $timer) {
                    $slaPolicy = $timer->slaPolicy;
                    $calendar = $timer->workingCalendar;
                    $totalDurationSeconds = $this->totalSlaDurationSeconds($calendar, $slaPolicy, $timer->started_at);
                    $remainingSeconds = $totalDurationSeconds - $timer->elapsed_before_pause;

                    if ($remainingSeconds <= 0) {
                        $remainingSeconds = 0;
                    }

                    $newDeadline = $this->workingDayCalculator->addWorkingSeconds($calendar, now(), $remainingSeconds);
                    $newWarning = $this->calculateWarning(now(), $newDeadline, $slaPolicy->warning_threshold_percentage);

                    $previousStatus = $newWarning && $newWarning <= now()
                        ? SlaTimerStatus::Warning
                        : SlaTimerStatus::Running;

                    $timer->update([
                        'status' => $previousStatus,
                        'paused_at' => null,
                        'deadline_at' => $newDeadline,
                        'warning_at' => $newWarning,
                    ]);

                    event(new SlaTimerResumed($timer));
                }
            });
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to resume timers for task', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.resume_all',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function calculateDeadline(WorkingCalendar $calendar, Carbon $startedAt, SlaPolicy $slaPolicy): Carbon
    {
        return match ($slaPolicy->sla_unit) {
            SlaUnit::Hours => $this->workingDayCalculator->addWorkingHours($calendar, $startedAt, $slaPolicy->sla_value),
            SlaUnit::Days => $this->workingDayCalculator->addWorkingDays($calendar, $startedAt, $slaPolicy->sla_value),
        };
    }

    private function calculateWarning(Carbon $startedAt, Carbon $deadlineAt, int $warningPercentage): ?Carbon
    {
        if ($warningPercentage <= 0 || $warningPercentage >= 100) {
            return null;
        }

        $totalSeconds = $deadlineAt->diffInSeconds($startedAt);
        $warningSeconds = (int) ($totalSeconds * $warningPercentage / 100);

        return $startedAt->copy()->addSeconds($warningSeconds);
    }

    private function totalSlaDurationSeconds(WorkingCalendar $calendar, SlaPolicy $slaPolicy, ?Carbon $startedAt = null): int
    {
        $startedAt = $startedAt ?? now();

        return match ($slaPolicy->sla_unit) {
            SlaUnit::Hours => $slaPolicy->sla_value * 3600,
            SlaUnit::Days => $this->workingDayCalculator->workingSecondsBetween(
                $calendar,
                $startedAt,
                $this->workingDayCalculator->addWorkingDays($calendar, $startedAt, $slaPolicy->sla_value)
            ),
        };
    }

    private function resolveWorkingCalendar(?int $departmentId): WorkingCalendar
    {
        return WorkingCalendar::where('is_default', true)->firstOrFail();
    }
}
