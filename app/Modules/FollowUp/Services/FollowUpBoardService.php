<?php

namespace App\Modules\FollowUp\Services;

use App\Models\User;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\FollowUp\Enums\BoardSortDirection;
use App\Modules\FollowUp\Enums\BoardSortField;
use App\Modules\FollowUp\Exceptions\InvalidBoardFilterException;
use App\Modules\FollowUp\Services\Concerns\EnrichesBoardTasks;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowUpBoardService
{
    use EnrichesBoardTasks;
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function board(User $user, array $filters = []): CursorPaginator
    {
        try {
            $query = $this->buildBaseQuery($user, $filters);

            $this->applyStatusFilter($query, $filters['status'] ?? null);
            $this->applyBoardFilters($query, $filters);
            $this->applySorting($query, $filters, $filters['status'] ?? null);

            $paginator = $query->cursorPaginate($filters['per_page'] ?? 15);
            $this->enrichTasks($paginator->items(), app(WorkingDayCalculator::class));

            return $paginator;
        } catch (InvalidBoardFilterException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('followup')->error('Failed to build follow-up board', [
                'tenant_slug' => tenant()->slug,
                'action' => 'followup.board',
                'entity_type' => 'followup_board',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function bottlenecks(User $user, array $filters = []): array
    {
        $this->ensureOrganizationOrFollowUpScope($user);

        $cacheKey = sprintf(
            '%s:followup:bottlenecks:%s:%s:%s',
            tenant()->slug,
            $user->public_id,
            $filters['department_id'] ?? 'all',
            $filters['limit'] ?? 10
        );

        return Cache::remember($cacheKey, 300, function () use ($user, $filters) {
            try {
                $visibleTaskIds = $this->baseTaskQuery($user)
                    ->where('tasks.status', TaskStatus::Active)
                    ->pluck('id');

                if ($visibleTaskIds->isEmpty()) {
                    return [];
                }

                $calendar = WorkingCalendar::where('is_default', true)->first();
                $calculator = app(WorkingDayCalculator::class);

                $breached = SlaTimerStatus::Breached->value;
                $warning = SlaTimerStatus::Warning->value;

                $rows = DB::table('tasks')
                    ->join('task_stage_instances', 'tasks.id', '=', 'task_stage_instances.task_id')
                    ->join('blueprint_stages', 'task_stage_instances.blueprint_stage_id', '=', 'blueprint_stages.id')
                    ->join('stage_types', 'blueprint_stages.stage_type_id', '=', 'stage_types.id')
                    ->join('departments', 'task_stage_instances.owning_department_id', '=', 'departments.id')
                    ->leftJoin('sla_timer_instances', 'task_stage_instances.id', '=', 'sla_timer_instances.stage_instance_id')
                    ->whereIn('tasks.id', $visibleTaskIds)
                    ->where('task_stage_instances.status', StageInstanceStatus::Active->value)
                    ->whereIn('sla_timer_instances.status', [$warning, $breached])
                    ->when(! empty($filters['department_id']), fn ($q) => $q->where('departments.public_id', $filters['department_id']))
                    ->select([
                        'stage_types.public_id as stage_type_public_id',
                        'stage_types.name_ar as stage_type_name_ar',
                        'stage_types.name_en as stage_type_name_en',
                        'departments.public_id as department_public_id',
                        'departments.name_ar as department_name_ar',
                        'departments.name_en as department_name_en',
                        'task_stage_instances.entered_at',
                        DB::raw('CASE WHEN sla_timer_instances.status = '.$breached.' THEN 1 ELSE 0 END as is_breached'),
                        DB::raw('CASE WHEN sla_timer_instances.status = '.$warning.' THEN 1 ELSE 0 END as is_at_risk'),
                    ])
                    ->get();

                $grouped = $rows->groupBy(fn ($row) => $row->stage_type_public_id.'|'.$row->department_public_id);

                return $grouped->map(function ($group) use ($calendar, $calculator) {
                    $first = $group->first();
                    $overdue = $group->sum('is_breached');
                    $atRisk = $group->sum('is_at_risk');
                    $avgSeconds = $group->avg(function ($row) use ($calendar, $calculator) {
                        if (! $calendar || ! $row->entered_at) {
                            return 0;
                        }

                        return $calculator->workingSecondsBetween($calendar, Carbon::parse($row->entered_at), Carbon::now());
                    });

                    return [
                        'stage_type' => [
                            'public_id' => $first->stage_type_public_id,
                            'name_ar' => $first->stage_type_name_ar,
                            'name_en' => $first->stage_type_name_en,
                        ],
                        'department' => [
                            'public_id' => $first->department_public_id,
                            'name_ar' => $first->department_name_ar,
                            'name_en' => $first->department_name_en,
                        ],
                        'overdue_count' => (int) $overdue,
                        'at_risk_count' => (int) $atRisk,
                        'score' => (int) (($overdue * 2) + $atRisk),
                        'average_time_at_stage_seconds' => (int) $avgSeconds,
                    ];
                })
                    ->sortByDesc('score')
                    ->values()
                    ->take($filters['limit'] ?? 10)
                    ->all();
            } catch (\Throwable $e) {
                Log::channel('followup')->error('Failed to compute follow-up bottlenecks', [
                    'tenant_slug' => tenant()->slug,
                    'action' => 'followup.bottlenecks',
                    'entity_type' => 'followup_bottlenecks',
                    'entity_id' => null,
                    'performed_by' => $user->public_id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    private function buildBaseQuery(User $user, array $filters): Builder
    {
        $query = $this->baseTaskQuery($user)
            ->with([
                'priority',
                'blueprint.category',
                'stageInstances' => fn ($q) => $q->where('status', StageInstanceStatus::Active)
                    ->with([
                        'blueprintStage.stageType',
                        'owningDepartment',
                        'assignments.user.position',
                        'subStageInstances' => fn ($sq) => $sq->where('status', SubStageInstanceStatus::Active)
                            ->with(['blueprintSubStage', 'assignments.user.position', 'owningDepartment']),
                    ]),
            ])
            ->leftJoin('task_stage_instances', function ($join) {
                $join->on('tasks.id', '=', 'task_stage_instances.task_id')
                    ->where('task_stage_instances.status', StageInstanceStatus::Active->value);
            })
            ->leftJoin('blueprint_stages', 'task_stage_instances.blueprint_stage_id', '=', 'blueprint_stages.id')
            ->leftJoin('stage_types', 'blueprint_stages.stage_type_id', '=', 'stage_types.id')
            ->leftJoin('departments', 'task_stage_instances.owning_department_id', '=', 'departments.id')
            ->leftJoin('task_priorities', 'tasks.priority_id', '=', 'task_priorities.id')
            ->select('tasks.*');

        return $query;
    }

    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if (! $status) {
            return;
        }

        match ($status) {
            'active' => $query->where('tasks.status', TaskStatus::Active),
            'suspended' => $query->where('tasks.status', TaskStatus::Suspended),
            'completed' => $query->where('tasks.status', TaskStatus::Completed),
            'cancelled' => $query->where('tasks.status', TaskStatus::Cancelled),
            'overdue' => $this->applySlaStatusFilter($query, SlaTimerStatus::Breached),
            'at_risk' => $this->applySlaStatusFilter($query, SlaTimerStatus::Warning),
            default => throw new InvalidBoardFilterException("Invalid status filter: {$status}"),
        };

        if (in_array($status, ['overdue', 'at_risk'], true)) {
            $query->where('tasks.status', TaskStatus::Active);
        }
    }

    private function applySlaStatusFilter(Builder $query, SlaTimerStatus $status): void
    {
        $taskIds = DB::table('sla_timer_instances')
            ->where('status', $status->value)
            ->pluck('task_id')
            ->unique()
            ->values()
            ->all();

        if (empty($taskIds)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('tasks.id', $taskIds);
        }
    }

    private function applyBoardFilters(Builder $query, array $filters): void
    {
        $this->applyFilters($query, $filters);

        if (! empty($filters['external_reference'])) {
            throw new InvalidBoardFilterException('External reference filtering is not available until external references are implemented.');
        }

        if (! empty($filters['stage_type_id'])) {
            $query->where('stage_types.public_id', $filters['stage_type_id']);
        }

        if (! empty($filters['assignee_id'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->whereHas('stageInstances', function ($sq) use ($filters) {
                    $sq->where('status', StageInstanceStatus::Active)
                        ->whereHas('assignments', function ($aq) use ($filters) {
                            $aq->where('user_id', function ($sub) use ($filters) {
                                $sub->select('id')->from('users')->where('public_id', $filters['assignee_id']);
                            })
                                ->where('is_completed', false)
                                ->whereNull('reassigned_at');
                        });
                })->orWhereHas('stageInstances', function ($sq) use ($filters) {
                    $sq->where('status', StageInstanceStatus::Active)
                        ->whereHas('subStageInstances', function ($ssq) use ($filters) {
                            $ssq->where('status', SubStageInstanceStatus::Active)
                                ->whereHas('assignments', function ($aq) use ($filters) {
                                    $aq->where('user_id', function ($sub) use ($filters) {
                                        $sub->select('id')->from('users')->where('public_id', $filters['assignee_id']);
                                    })
                                        ->where('is_completed', false)
                                        ->whereNull('reassigned_at');
                                });
                        });
                });
            });
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->whereRaw('LOWER(tasks.title_ar) LIKE LOWER(?)', ["%{$search}%"])
                    ->orWhereRaw('LOWER(tasks.title_en) LIKE LOWER(?)', ["%{$search}%"]);
            });
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $dateField = $filters['date_field'] ?? 'created_at';
            $this->applyDateRange($query, $filters, "tasks.{$dateField}");
        }
    }

    private function applySorting(Builder $query, array $filters, ?string $status = null): void
    {
        $rawField = $filters['sort_by'] ?? null;
        $direction = BoardSortDirection::tryFrom($filters['sort_direction'] ?? BoardSortDirection::Desc->value)?->value ?? 'desc';

        if ($status === 'overdue') {
            $query->orderBy('task_stage_instances.entered_at', 'asc');
            $query->orderBy('tasks.id');

            return;
        }

        if ($status === 'at_risk') {
            $query->leftJoin('sla_timer_instances as sla_sort', function ($join) {
                $join->on('task_stage_instances.id', '=', 'sla_sort.stage_instance_id')
                    ->whereIn('sla_sort.status', [SlaTimerStatus::Warning->value]);
            });
            $query->orderBy('sla_sort.deadline_at', 'asc');
            $query->orderBy('tasks.id');

            return;
        }

        $field = $rawField !== null
            ? (BoardSortField::tryFrom($rawField) ?? BoardSortField::TimeAtStage)
            : BoardSortField::TimeAtStage;

        match ($field) {
            BoardSortField::TimeAtStage => $query->orderBy(
                'task_stage_instances.entered_at',
                $direction === 'asc' ? 'desc' : 'asc'
            ),
            BoardSortField::Priority => $query->orderBy('task_priorities.severity_rank', $direction),
            BoardSortField::DueDate => $query->orderBy('tasks.due_date', $direction),
            BoardSortField::CreatedAt => $query->orderBy('tasks.created_at', $direction),
            BoardSortField::Department => $query->orderBy('departments.name_ar', $direction),
            BoardSortField::StageType => $query->orderBy('stage_types.name_ar', $direction),
        };

        $query->orderBy('tasks.id');
    }

    private function ensureOrganizationOrFollowUpScope(User $user): void
    {
        $hasOrgView = $this->iamPolicy->hasCapability($user, 'task.view.organization');
        $hasFollowUp = $this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope');

        if (! $hasOrgView && ! $hasFollowUp) {
            throw new InvalidBoardFilterException('This action requires task.view.organization or task.view.follow_up_scope capability.');
        }
    }
}
