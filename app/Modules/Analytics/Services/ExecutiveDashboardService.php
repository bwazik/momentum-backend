<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\Analytics\Enums\DepartmentHealth;
use App\Modules\Analytics\Exceptions\AnalyticsScopeDeniedException;
use App\Modules\Analytics\Exceptions\InvalidReportFilterException;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecutiveDashboardService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function summary(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        $cacheKey = $this->cacheKey('executive_summary').$this->filterHash($filters);
        $this->trackCacheKey('executive_summary', $cacheKey);

        return Cache::remember($cacheKey, 300, function () use ($user, $filters) {
            try {
                $overdueTaskIds = $this->slaTaskIds(SlaTimerStatus::Breached);
                $atRiskTaskIds = $this->slaTaskIds(SlaTimerStatus::Warning);

                $base = $this->baseTaskQuery($user);
                $this->applyDateRange($base, $filters);
                $this->applyFilters($base, $filters);

                $active = (clone $base)->where('status', TaskStatus::Active)->count();
                $suspended = (clone $base)->where('status', TaskStatus::Suspended)->count();
                $completed = $this->countByStatusAndDate(clone $base, TaskStatus::Completed, $filters);
                $cancelled = $this->countByStatusAndDate(clone $base, TaskStatus::Cancelled, $filters);

                $overdue = ! empty($overdueTaskIds) ? (clone $base)->whereIn('id', $overdueTaskIds)->count() : 0;
                $atRisk = ! empty($atRiskTaskIds) ? (clone $base)->whereIn('id', $atRiskTaskIds)->count() : 0;

                $denominator = $completed + $cancelled + $active + $suspended;

                return [
                    'active' => $active,
                    'overdue' => $overdue,
                    'at_risk' => $atRisk,
                    'suspended' => $suspended,
                    'completed' => $completed,
                    'cancelled' => $cancelled,
                    'completion_rate' => $denominator > 0 ? round($completed / $denominator, 4) : 0,
                ];
            } catch (\Throwable $e) {
                Log::channel('analytics')->error('Failed to compute executive summary', [
                    'tenant_slug' => tenant()->slug,
                    'action' => 'analytics.executive_summary',
                    'entity_type' => 'analytics_report',
                    'entity_id' => 'executive_summary',
                    'performed_by' => $user->public_id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function bottlenecks(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        try {
            $visibleTaskIds = $this->baseTaskQuery($user)->where('status', TaskStatus::Active)->pluck('id');

            if ($visibleTaskIds->isEmpty()) {
                return [];
            }

            $limit = $filters['limit'] ?? 10;

            $driver = DB::connection()->getDriverName();

            $avgTimeExpr = $driver === 'sqlite'
                ? 'AVG((strftime(\'%s\', \'now\') - strftime(\'%s\', task_stage_instances.entered_at)))'
                : 'AVG(EXTRACT(EPOCH FROM (NOW() - task_stage_instances.entered_at)))';

            $rows = DB::table('tasks')
                ->join('task_stage_instances', 'tasks.id', '=', 'task_stage_instances.task_id')
                ->join('blueprint_stages', 'task_stage_instances.blueprint_stage_id', '=', 'blueprint_stages.id')
                ->join('stage_types', 'blueprint_stages.stage_type_id', '=', 'stage_types.id')
                ->join('departments', 'task_stage_instances.owning_department_id', '=', 'departments.id')
                ->leftJoin('sla_timer_instances', 'task_stage_instances.id', '=', 'sla_timer_instances.stage_instance_id')
                ->whereIn('tasks.id', $visibleTaskIds)
                ->where('task_stage_instances.status', StageInstanceStatus::Active->value)
                ->whereIn('sla_timer_instances.status', [SlaTimerStatus::Warning->value, SlaTimerStatus::Breached->value])
                ->when(! empty($filters['department_id']), fn ($q) => $q->where('departments.public_id', $filters['department_id']))
                ->select([
                    'stage_types.public_id as stage_type_public_id',
                    'stage_types.name_ar as stage_type_name_ar',
                    'stage_types.name_en as stage_type_name_en',
                    'departments.public_id as department_public_id',
                    'departments.name_ar as department_name_ar',
                    'departments.name_en as department_name_en',
                    DB::raw('COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Breached->value.' THEN 1 ELSE 0 END), 0) as overdue_count'),
                    DB::raw('COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Warning->value.' THEN 1 ELSE 0 END), 0) as at_risk_count'),
                    DB::raw('(COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Breached->value.' THEN 1 ELSE 0 END), 0) * 2 + COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Warning->value.' THEN 1 ELSE 0 END), 0)) as score'),
                    DB::raw($avgTimeExpr.' as avg_time_at_stage_seconds'),
                ])
                ->groupBy('stage_types.id', 'departments.id')
                ->havingRaw('(COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Breached->value.' THEN 1 ELSE 0 END), 0) * 2 + COALESCE(SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Warning->value.' THEN 1 ELSE 0 END), 0)) > 0')
                ->orderByDesc('score')
                ->orderByDesc('avg_time_at_stage_seconds')
                ->limit($limit)
                ->get();

            return $rows->map(fn ($row) => [
                'stage_type' => [
                    'public_id' => $row->stage_type_public_id,
                    'name_ar' => $row->stage_type_name_ar,
                    'name_en' => $row->stage_type_name_en,
                ],
                'department' => [
                    'public_id' => $row->department_public_id,
                    'name_ar' => $row->department_name_ar,
                    'name_en' => $row->department_name_en,
                ],
                'overdue_count' => (int) $row->overdue_count,
                'at_risk_count' => (int) $row->at_risk_count,
                'score' => (int) $row->score,
                'average_time_at_stage_seconds' => (int) $row->avg_time_at_stage_seconds,
            ])->all();
        } catch (\Throwable $e) {
            Log::channel('analytics')->error('Failed to compute bottlenecks', [
                'tenant_slug' => tenant()->slug,
                'action' => 'analytics.bottlenecks',
                'entity_type' => 'analytics_report',
                'entity_id' => 'bottlenecks',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function departmentHealth(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        try {
            $overdueTaskIds = $this->slaTaskIds(SlaTimerStatus::Breached);
            $atRiskTaskIds = $this->slaTaskIds(SlaTimerStatus::Warning);

            return Department::where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(function (Department $department) use ($user, $filters, $overdueTaskIds, $atRiskTaskIds) {
                    $base = $this->baseTaskQuery($user)
                        ->whereHas('stageInstances', fn ($q) => $q->where('owning_department_id', $department->id));

                    $this->applyDateRange($base, $filters);
                    $this->applyFilters($base, $filters);

                    $overdue = ! empty($overdueTaskIds) ? (clone $base)->whereIn('id', $overdueTaskIds)->count() : 0;
                    $atRisk = ! empty($atRiskTaskIds) ? (clone $base)->whereIn('id', $atRiskTaskIds)->count() : 0;
                    $active = (clone $base)->where('status', TaskStatus::Active)->count();

                    $health = $this->resolveDepartmentHealth($overdue, $atRisk);

                    return [
                        'department_public_id' => $department->public_id,
                        'department_name_ar' => $department->name_ar,
                        'department_name_en' => $department->name_en,
                        'health' => $health->value,
                        'health_label' => $health->name,
                        'active_tasks' => $active,
                        'overdue_tasks' => $overdue,
                        'at_risk_tasks' => $atRisk,
                    ];
                })->all();
        } catch (\Throwable $e) {
            Log::channel('analytics')->error('Failed to compute department health', [
                'tenant_slug' => tenant()->slug,
                'action' => 'analytics.department_health',
                'entity_type' => 'analytics_report',
                'entity_id' => 'department_health',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function drillDown(User $user, string $metric, array $filters)
    {
        $this->ensureOrganizationAccess($user);

        try {
            $query = $this->baseTaskQuery($user);

            match ($metric) {
                'active' => $query->where('status', TaskStatus::Active),
                'suspended' => $query->where('status', TaskStatus::Suspended),
                'completed' => $query->where('status', TaskStatus::Completed),
                'cancelled' => $query->where('status', TaskStatus::Cancelled),
                'overdue' => $this->applySlaFilter($query, SlaTimerStatus::Breached),
                'at_risk' => $this->applySlaFilter($query, SlaTimerStatus::Warning),
                default => throw new InvalidReportFilterException("Invalid metric: {$metric}"),
            };

            if (! empty($filters['stage_type_id'])) {
                $query->whereHas('stageInstances', fn ($q) => $q->whereHas('blueprintStage.stageType', fn ($sq) => $sq->where('public_id', $filters['stage_type_id'])));
            }

            $dateColumn = match ($metric) {
                'completed' => 'completed_at',
                'cancelled' => 'cancelled_at',
                default => 'created_at',
            };
            $this->applyDateRange($query, $filters, $dateColumn);
            $this->applyFilters($query, $filters);

            return $query->orderBy('id')->cursorPaginate($filters['per_page'] ?? 15);
        } catch (\Throwable $e) {
            Log::channel('analytics')->error('Failed to compute drill-down', [
                'tenant_slug' => tenant()->slug,
                'action' => 'analytics.drill_down',
                'entity_type' => 'analytics_report',
                'entity_id' => "executive_summary.{$metric}",
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function ensureOrganizationAccess(User $user): void
    {
        if (! $this->iamPolicy->hasCapability($user, 'analytics.view.organization')) {
            throw new AnalyticsScopeDeniedException;
        }
    }

    private function countByStatusAndDate($query, TaskStatus $status, array $filters): int
    {
        $q = (clone $query)->where('status', $status);

        $dateColumn = match ($status) {
            TaskStatus::Completed => 'completed_at',
            TaskStatus::Cancelled => 'cancelled_at',
            default => 'created_at',
        };

        $this->applyDateRange($q, $filters, $dateColumn);

        return $q->count();
    }

    private function resolveDepartmentHealth(int $overdue, int $atRisk): DepartmentHealth
    {
        if ($overdue > 5 || $atRisk > 10) {
            return DepartmentHealth::Red;
        }

        if ($atRisk > 3) {
            return DepartmentHealth::Amber;
        }

        return DepartmentHealth::Green;
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

    private function applySlaFilter($query, SlaTimerStatus $status): void
    {
        $taskIds = $this->slaTaskIds($status);
        if (! empty($taskIds)) {
            $query->whereIn('id', $taskIds);
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    private function filterHash(array $filters): string
    {
        $relevant = array_intersect_key($filters, array_flip(['date_from', 'date_to', 'priority_id', 'department_id', 'blueprint_category_id']));
        ksort($relevant);

        return ':'.md5(json_encode($relevant));
    }
}
