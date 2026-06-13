<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\Analytics\Exceptions\AnalyticsScopeDeniedException;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgingReportService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function aging(User $user, array $filters)
    {
        $this->ensureAnalyticsOrFollowUpAccess($user);

        try {
            $query = $this->baseTaskQuery($user)
                ->whereIn('tasks.status', [TaskStatus::Active, TaskStatus::Suspended])
                ->with([
                    'priority',
                    'blueprint.category',
                    'stageInstances' => fn ($q) => $q->where('status', StageInstanceStatus::Active)
                        ->with([
                            'blueprintStage.stageType',
                            'assignments.user',
                            'subStageInstances' => fn ($sq) => $sq->where('status', SubStageInstanceStatus::Active)
                                ->with(['blueprintSubStage', 'assignments.user']),
                        ]),
                ])
                ->leftJoin('task_stage_instances', function ($join) {
                    $join->on('tasks.id', '=', 'task_stage_instances.task_id')
                        ->where('task_stage_instances.status', StageInstanceStatus::Active->value);
                })
                ->select('tasks.*')
                ->orderBy('task_stage_instances.entered_at')
                ->orderBy('tasks.id');

            if (! empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (! empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            if (! empty($filters['status'])) {
                $query->where('tasks.status', $filters['status']);
            }

            if (! empty($filters['priority_id'])) {
                $query->whereHas('priority', fn ($q) => $q->where('public_id', $filters['priority_id']));
            }

            if (! empty($filters['department_id'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereHas('stageInstances', fn ($sq) => $sq->whereHas('owningDepartment', fn ($dq) => $dq->where('public_id', $filters['department_id'])))
                        ->orWhereHas('stageInstances.subStageInstances', fn ($sq) => $sq->whereHas('owningDepartment', fn ($dq) => $dq->where('public_id', $filters['department_id'])));
                });
            }

            if (! empty($filters['blueprint_category_id'])) {
                $query->whereHas('blueprint', fn ($q) => $q->whereHas('category', fn ($cq) => $cq->where('public_id', $filters['blueprint_category_id'])));
            }

            return $query->cursorPaginate($filters['per_page'] ?? 15);
        } catch (\Throwable $e) {
            Log::channel('analytics')->error('Failed to compute aging report', [
                'tenant_slug' => tenant()->slug,
                'action' => 'analytics.aging_report',
                'entity_type' => 'analytics_report',
                'entity_id' => 'aging_report',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function loadSlaHealthForTasks($tasks): void
    {
        $stageIds = collect();
        $subStageIds = collect();

        foreach ($tasks as $task) {
            $stage = $task->stageInstances->first();
            if ($stage) {
                $sub = $stage->subStageInstances->first();
                if ($sub) {
                    $subStageIds->push($sub->id);
                } else {
                    $stageIds->push($stage->id);
                }
            }
        }

        $stageTimers = $stageIds->isNotEmpty()
            ? DB::table('sla_timer_instances')->whereIn('stage_instance_id', $stageIds)->get()->keyBy('stage_instance_id')
            : collect();

        $subStageTimers = $subStageIds->isNotEmpty()
            ? DB::table('sla_timer_instances')->whereIn('sub_stage_instance_id', $subStageIds)->get()->keyBy('sub_stage_instance_id')
            : collect();

        foreach ($tasks as $task) {
            $health = 'none';
            $enteredAt = null;

            if ($task->status === TaskStatus::Suspended) {
                $health = 'grey';
            } else {
                $stage = $task->stageInstances->first();
                $sub = $stage?->subStageInstances->first();
                $current = $sub ?? $stage;

                if ($current) {
                    $timer = $sub
                        ? $subStageTimers->get($current->id)
                        : $stageTimers->get($current->id);

                    $health = match ($timer?->status) {
                        SlaTimerStatus::Breached->value => 'red',
                        SlaTimerStatus::Warning->value => 'amber',
                        default => 'green',
                    };
                    $enteredAt = $current->entered_at;
                }
            }

            $task->setAttribute('_sla_health', $health);
            $task->setAttribute('_step_entered_at', $enteredAt);
        }
    }

    private function ensureAnalyticsOrFollowUpAccess(User $user): void
    {
        $hasOrg = $this->iamPolicy->hasCapability($user, 'analytics.view.organization');
        $hasDept = $this->iamPolicy->hasCapability($user, 'analytics.view.department');
        $hasFollowUp = $this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope');

        if (! ($hasOrg || $hasDept || $hasFollowUp)) {
            throw new AnalyticsScopeDeniedException;
        }
    }
}
