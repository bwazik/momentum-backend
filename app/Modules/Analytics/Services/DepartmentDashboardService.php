<?php

namespace App\Modules\Analytics\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Analytics\Exceptions\AnalyticsScopeDeniedException;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepartmentDashboardService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function performance(User $user, Department $department, array $filters): array
    {
        $this->authorizeDepartment($user, $department);

        $cacheKey = $this->cacheKey("department:{$department->public_id}:performance").$this->filterHash($filters);
        $this->trackCacheKey('department', $cacheKey);

        return Cache::remember($cacheKey, 300, function () use ($user, $department, $filters) {
            try {
                $overdueTaskIds = $this->slaTaskIds(SlaTimerStatus::Breached);
                $atRiskTaskIds = $this->slaTaskIds(SlaTimerStatus::Warning);

                $base = $this->baseTaskQuery($user)
                    ->where(function ($q) use ($department) {
                        $q->whereHas('stageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id))
                            ->orWhereHas('stageInstances.subStageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id));
                    });

                $this->applyDateRange($base, $filters);
                $this->applyFilters($base, $filters);

                $active = (clone $base)->where('status', TaskStatus::Active)->count();
                $overdue = ! empty($overdueTaskIds) ? (clone $base)->whereIn('id', $overdueTaskIds)->count() : 0;
                $atRisk = ! empty($atRiskTaskIds) ? (clone $base)->whereIn('id', $atRiskTaskIds)->count() : 0;

                $visibleTaskIds = $base->pluck('id')->all();

                $driver = DB::connection()->getDriverName();

                $avgDelayExpr = $driver === 'sqlite'
                    ? 'AVG(strftime(\'%s\', exited_at) - strftime(\'%s\', entered_at))'
                    : 'AVG(EXTRACT(EPOCH FROM (exited_at - entered_at)))';

                $avgDelay = empty($visibleTaskIds)
                    ? 0
                    : (TaskStageInstance::where('owning_department_id', $department->id)
                        ->whereIn('task_id', $visibleTaskIds)
                        ->where('status', StageInstanceStatus::Completed)
                        ->whereNotNull('entered_at')
                        ->whereNotNull('exited_at')
                        ->selectRaw($avgDelayExpr.' as avg_seconds')
                        ->value('avg_seconds') ?? 0);

                return [
                    'department_public_id' => $department->public_id,
                    'active_tasks' => $active,
                    'overdue_tasks' => $overdue,
                    'at_risk_tasks' => $atRisk,
                    'average_stage_delay_seconds' => (int) $avgDelay,
                ];
            } catch (\Throwable $e) {
                Log::channel('analytics')->error('Failed to compute department performance', [
                    'tenant_slug' => tenant()->slug,
                    'action' => 'analytics.department_performance',
                    'entity_type' => 'department',
                    'entity_id' => $department->public_id,
                    'performed_by' => $user->public_id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function team(User $user, Department $department): array
    {
        $this->authorizeDepartment($user, $department);

        $cacheKey = $this->cacheKey("department:{$department->public_id}:team");

        $this->trackCacheKey('department', $cacheKey);

        return Cache::remember($cacheKey, 300, function () use ($user, $department) {
            try {
                $visibleTaskIds = $this->baseTaskQuery($user)->pluck('id')->all();

                if (empty($visibleTaskIds)) {
                    return [];
                }

                $userIds = UserPositionAssignment::whereHas('position', fn ($q) => $q->where('department_id', $department->id))
                    ->whereNull('ended_at')
                    ->pluck('user_id');

                $overdueStageIds = DB::table('sla_timer_instances')
                    ->where('status', SlaTimerStatus::Breached->value)
                    ->whereNotNull('stage_instance_id')
                    ->whereIn('task_id', $visibleTaskIds)
                    ->pluck('stage_instance_id');

                $overdueSubStageIds = DB::table('sla_timer_instances')
                    ->where('status', SlaTimerStatus::Breached->value)
                    ->whereNotNull('sub_stage_instance_id')
                    ->whereIn('task_id', $visibleTaskIds)
                    ->pluck('sub_stage_instance_id');

                return $userIds->map(function (int $userId) use ($department, $visibleTaskIds, $overdueStageIds, $overdueSubStageIds) {
                    $activeAssignments = TaskStageAssignment::where('user_id', $userId)
                        ->where('is_completed', false)
                        ->whereHas('task', fn ($q) => $q->whereIn('id', $visibleTaskIds))
                        ->where(function ($q) use ($department) {
                            $q->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', StageInstanceStatus::Active))
                                ->orWhereHas('subStageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', SubStageInstanceStatus::Active));
                        })
                        ->count();

                    $overdueAssignments = TaskStageAssignment::where('user_id', $userId)
                        ->where('is_completed', false)
                        ->whereHas('task', fn ($q) => $q->whereIn('id', $visibleTaskIds))
                        ->where(function ($q) use ($department) {
                            $q->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', StageInstanceStatus::Active))
                                ->orWhereHas('subStageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', SubStageInstanceStatus::Active));
                        })
                        ->where(function ($q) use ($overdueStageIds, $overdueSubStageIds) {
                            if ($overdueStageIds->isNotEmpty()) {
                                $q->whereIn('stage_instance_id', $overdueStageIds);
                            }
                            if ($overdueSubStageIds->isNotEmpty()) {
                                $q->orWhereIn('sub_stage_instance_id', $overdueSubStageIds);
                            }
                            if ($overdueStageIds->isEmpty() && $overdueSubStageIds->isEmpty()) {
                                $q->whereRaw('1 = 0');
                            }
                        })
                        ->count();

                    $completedStages = TaskStageAssignment::where('user_id', $userId)
                        ->where('is_completed', true)
                        ->whereHas('task', fn ($q) => $q->whereIn('id', $visibleTaskIds))
                        ->where(function ($q) use ($department) {
                            $q->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id))
                                ->orWhereHas('subStageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id));
                        })
                        ->count();

                    return [
                        'user_public_id' => User::find($userId)?->public_id,
                        'active_assignments' => $activeAssignments,
                        'overdue_assignments' => $overdueAssignments,
                        'completed_stages' => $completedStages,
                    ];
                })->filter(fn ($row) => $row['user_public_id'] !== null)->values()->all();
            } catch (\Throwable $e) {
                Log::channel('analytics')->error('Failed to compute team metrics', [
                    'tenant_slug' => tenant()->slug,
                    'action' => 'analytics.team_metrics',
                    'entity_type' => 'department',
                    'entity_id' => $department->public_id,
                    'performed_by' => $user->public_id ?? 'system',
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function drillDown(User $user, Department $department, array $filters)
    {
        $this->authorizeDepartment($user, $department);

        try {
            $query = $this->baseTaskQuery($user)
                ->where(function ($q) use ($department) {
                    $q->whereHas('stageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id))
                        ->orWhereHas('stageInstances.subStageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id));
                });

            $this->applyDateRange($query, $filters);
            $this->applyFilters($query, $filters);

            return $query->orderBy('id')->cursorPaginate($filters['per_page'] ?? 15);
        } catch (\Throwable $e) {
            Log::channel('analytics')->error('Failed to compute department drill-down', [
                'tenant_slug' => tenant()->slug,
                'action' => 'analytics.department_drill_down',
                'entity_type' => 'department',
                'entity_id' => $department->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function authorizeDepartment(User $user, Department $department): void
    {
        $hasOrg = $this->iamPolicy->hasCapability($user, 'analytics.view.organization');
        $hasDept = $this->iamPolicy->check($user, 'analytics.view.department', ScopeType::SPECIFIC_DEPARTMENT, $department->id);
        $hasIndividuals = $this->iamPolicy->check($user, 'analytics.view.individuals_in_department', ScopeType::SPECIFIC_DEPARTMENT, $department->id);

        if (! ($hasOrg || $hasDept || $hasIndividuals)) {
            throw new AnalyticsScopeDeniedException;
        }
    }

    private function slaTaskIds(SlaTimerStatus $status): array
    {
        return DB::table('sla_timer_instances')
            ->where('status', $status->value)
            ->pluck('task_id')
            ->unique()
            ->values()
            ->all();
    }

    private function filterHash(array $filters): string
    {
        $relevant = array_intersect_key($filters, array_flip(['date_from', 'date_to', 'priority_id', 'department_id', 'blueprint_category_id']));
        ksort($relevant);

        return ':'.md5(json_encode($relevant));
    }
}
