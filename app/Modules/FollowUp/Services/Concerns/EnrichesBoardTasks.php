<?php

namespace App\Modules\FollowUp\Services\Concerns;

use App\Modules\FollowUp\Enums\SlaHealth;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait EnrichesBoardTasks
{
    private function enrichTasks($items, WorkingDayCalculator $calculator): void
    {
        $tasks = collect($items);

        if ($tasks->isEmpty()) {
            return;
        }

        $calendar = WorkingCalendar::where('is_default', true)->firstOrFail();
        $stageIds = collect();
        $subStageIds = collect();

        foreach ($tasks as $task) {
            $step = $this->currentStep($task);
            $task->setAttribute('_current_step', $step);

            $assignees = $step
                ? $step->assignments
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->values()
                : collect();
            $task->setAttribute('_current_assignees', $assignees);

            if ($step instanceof TaskSubStageInstance) {
                $subStageIds->push($step->id);
            } elseif ($step instanceof TaskStageInstance) {
                $stageIds->push($step->id);
            }
        }

        $stageTimers = $stageIds->isNotEmpty()
            ? DB::table('sla_timer_instances')->whereIn('stage_instance_id', $stageIds)->get()->keyBy('stage_instance_id')
            : collect();

        $subStageTimers = $subStageIds->isNotEmpty()
            ? DB::table('sla_timer_instances')->whereIn('sub_stage_instance_id', $subStageIds)->get()->keyBy('sub_stage_instance_id')
            : collect();

        foreach ($tasks as $task) {
            $step = $task->_current_step;

            if (! $step) {
                $task->setAttribute('_time_at_stage_seconds', 0);
                $task->setAttribute('_sla_health', SlaHealth::Green);

                continue;
            }

            $enteredAt = $step->entered_at ?? $task->created_at;
            $task->setAttribute('_time_at_stage_seconds', $calculator->workingSecondsBetween($calendar, $enteredAt, Carbon::now()));

            if ($task->status === TaskStatus::Suspended) {
                $task->setAttribute('_sla_health', SlaHealth::Grey);

                continue;
            }

            $timer = $step instanceof TaskSubStageInstance
                ? $subStageTimers->get($step->id)
                : $stageTimers->get($step->id);

            $task->setAttribute('_sla_health', match ($timer?->status) {
                SlaTimerStatus::Breached->value => SlaHealth::Red,
                SlaTimerStatus::Warning->value => SlaHealth::Amber,
                SlaTimerStatus::Paused->value => SlaHealth::Grey,
                default => SlaHealth::Green,
            });
        }
    }

    private function currentStep($task): TaskStageInstance|TaskSubStageInstance|null
    {
        $activeStage = $task->stageInstances->first();

        if (! $activeStage) {
            return null;
        }

        return $activeStage->subStageInstances->first() ?? $activeStage;
    }
}
