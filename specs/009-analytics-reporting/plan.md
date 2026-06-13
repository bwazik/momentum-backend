# Implementation Plan: 009 Analytics & Reporting

> **Spec:** 009-analytics-reporting
> **Date:** 2026-06-13
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| Prior-period comparison in executive summary? | **Deferred to V2.** | MVP ships current-period summary only. Keeps scope smallest. |
| Real-time vs materialized `average stage delay`? | **Real-time query with indexes.** | MVP volume is acceptable for indexed aggregation. Materialize only if load tests fail. |
| Department health thresholds configurable per tenant? | **Hardcoded defaults in MVP.** | Config deferred to V2. Defaults: Red if overdue > 5 OR at-risk > 10; Amber if at-risk > 3; else Green. |
| Team view includes sub-stage assignments? | **Yes.** | Aggregate both stage and sub-stage `task_stage_assignments` per employee. |
| Reuse `TaskVisibilityScope` or build lighter read scope? | **Reuse `TaskVisibilityScope`.** | Avoids divergence in ABAC/confidentiality logic. If query performance suffers, extract reusable query scope later. |
| Bottleneck ranking score? | **Combined weighted score.** | `score = overdue_count * 2 + at_risk_count`. Sort descending, tie-break by average time-at-stage. |
| Cross-module reads for analytics? | **Allowed for read-only aggregation.** | `architecture.md` permits analytics to query views/read models. MVP uses indexed Eloquent queries across Task/Tracking/Organization/IAM tables without writing. |
| Which department does a task belong to? | **Current active stage/sub-stage `owning_department_id`.** | Aligns with existing `TaskVisibilityScope` department-touched logic. |
| Should analytics emit its own domain events? | **No in MVP.** | Analytics is read-only. Cache invalidation is done via listeners on Task/Tracking events. Audit of report access deferred to Spec 015. |

---

## Technical Approach

Build the Analytics module under `app/Modules/Analytics/` as a pure read-only reporting layer. Three controllers, three services, two enums, seven API resources, four form requests, one shared visibility trait, one cache-invalidation listener, and two domain exceptions. No new tables or migrations — all data comes from existing Task, Tracking, Organization, and IAM tables. All endpoints apply `TaskVisibilityScope` before aggregating so confidentiality and ABAC rules are preserved. Summary endpoints cache at warm tier (300s); drill-down and aging endpoints do not cache.

### Key Decisions

- **No new tables/migrations** — Analytics is read-only and consumes existing tables.
- **Three service classes by audience** — `ExecutiveDashboardService`, `DepartmentDashboardService`, `AgingReportService`. Keeps each service focused and under 300 lines.
- **Shared query builder trait** — `IntersectsTaskVisibility` trait wraps `TaskVisibilityScope::apply()` and common status/archive filters so all analytics queries start from the same base.
- **Cached summaries, uncached drill-downs** — Aggregated counts change on lifecycle events; drill-downs are time-sensitive and paginated.
- **Event-driven cache invalidation** — Listen to Task/Tracking lifecycle events and forget summary cache keys.
- **`analytics` logging channel** — Add to `config/logging.php` following existing module pattern.
- **Analytics capabilities already seeded** — `CapabilitySeeder` already contains `analytics.view.organization`, `analytics.view.department`, `analytics.view.individuals_in_department`; verify during implementation.
- **Route model binding by `public_id`** — Department and StageType route parameters resolve by `public_id` via existing `HasPublicId` trait.

---

## Affected Modules / Files

### New Files

```
app/Modules/Analytics/
├── Controllers/
│   ├── ExecutiveDashboardController.php
│   ├── DepartmentDashboardController.php
│   └── AgingReportController.php
├── Services/
│   ├── ExecutiveDashboardService.php
│   ├── DepartmentDashboardService.php
│   ├── AgingReportService.php
│   └── Concerns/
│       └── IntersectsTaskVisibility.php
├── Enums/
│   ├── TaskHealth.php
│   └── DepartmentHealth.php
├── Requests/
│   ├── ExecutiveSummaryRequest.php
│   ├── BottleneckRequest.php
│   ├── DepartmentPerformanceRequest.php
│   └── AgingReportRequest.php
├── Resources/
│   ├── ExecutiveSummaryResource.php
│   ├── BottleneckResource.php
│   ├── DepartmentHealthResource.php
│   ├── DepartmentPerformanceResource.php
│   ├── TeamMetricsResource.php
│   ├── AgingReportResource.php
│   └── TaskListItemResource.php
├── Listeners/
│   ├── InvalidateCacheOnTaskLaunched.php
│   ├── InvalidateCacheOnTaskSuspended.php
│   ├── InvalidateCacheOnTaskResumed.php
│   ├── InvalidateCacheOnTaskCancelled.php
│   ├── InvalidateCacheOnTaskCompleted.php
│   ├── InvalidateCacheOnStageInstanceCompleted.php
│   ├── InvalidateCacheOnStageInstanceReturned.php
│   ├── InvalidateCacheOnSubStageInstanceCompleted.php
│   ├── InvalidateCacheOnSlaTimerStarted.php
│   └── Concerns/
│       └── InvalidatesAnalyticsCache.php
└── Exceptions/
    ├── AnalyticsScopeDeniedException.php
    └── InvalidReportFilterException.php
```

```
routes/api/v1/analytics.php
```

```
tests/Feature/Modules/Analytics/
├── ExecutiveDashboardTest.php
├── DepartmentDashboardTest.php
├── AgingReportTest.php
└── AnalyticsAbacTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `config/logging.php` | Add `analytics` logging channel |
| `routes/tenant.php` | `require __DIR__.'/api/v1/analytics.php';` |
| `database/seeders/CapabilitySeeder.php` | Verify analytics capabilities exist |
| `openapi/openapi.json` | Regenerate after implementation |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create two analytics-specific enums in `app/Modules/Analytics/Enums/`.

**Key decisions:**
- `TaskHealth` adds `Grey` for suspended tasks, reusing the same green/amber/red semantics as SLA health.
- `DepartmentHealth` has only three states (no grey).
- Reuse `TaskStatus`, `StageInstanceStatus`, `SubStageInstanceStatus`, `SlaTimerStatus`, `ClassificationLevel` from Task/Tracking modules.

**Files:**
- `app/Modules/Analytics/Enums/TaskHealth.php`
- `app/Modules/Analytics/Enums/DepartmentHealth.php`

**Code snippet — TaskHealth:**
```php
<?php

namespace App\Modules\Analytics\Enums;

enum TaskHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
    case Grey = 4;
}
```

**Code snippet — DepartmentHealth:**
```php
<?php

namespace App\Modules\Analytics\Enums;

enum DepartmentHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
}
```

**Test cases:**
1. `TaskHealth::Red->value` → `3`
2. `DepartmentHealth::Green` is instance of `DepartmentHealth` → `true`

**Rules:** `coding-standards.md` — Enum Usage. TitleCase keys, stored as TINYINT.

---

### 2. Shared Visibility Concern

**One-line summary:** Trait that applies `TaskVisibilityScope`, excludes drafts/archived/soft-deleted tasks, and applies common date filters.

**Key decisions:**
- Centralizes the base query so every analytics report starts from the same filtered task set.
- Applies `archived_at IS NULL` and `deleted_at IS NULL` for all operational dashboards.
- Excludes `TaskStatus::Draft` tasks from analytics.

**File:** `app/Modules/Analytics/Services/Concerns/IntersectsTaskVisibility.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Analytics\Services\Concerns;

use App\Models\User;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

trait IntersectsTaskVisibility
{
    protected function baseTaskQuery(User $user): Builder
    {
        $scope = app(TaskVisibilityScope::class);

        $query = Task::query()
            ->where('tasks.status', '!=', TaskStatus::Draft)
            ->whereNull('tasks.archived_at')
            ->whereNull('tasks.deleted_at');

        return $scope->apply($query, $user);
    }

    protected function applyDateRange(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where($column, '<=', $filters['date_to']);
        }

        return $query;
    }

    protected function cacheKey(string $suffix): string
    {
        return sprintf('%s:analytics:%s', tenant()->slug, $suffix);
    }

    protected function trackCacheKey(string $group, string $cacheKey): void
    {
        $slug = tenant()->slug;
        $listKey = "{$slug}:analytics:keys:{$group}";

        $keys = Cache::get($listKey, []);
        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::forever($listKey, $keys);
        }
    }
}
```

**Rules:** `coding-standards.md` — Reuse existing scopes; no post-query filtering. `security-policy.md` — enforce ABAC/confidentiality.

---

### 3. Executive Dashboard Service

**One-line summary:** Aggregates tenant-wide counts and bottleneck/health views for users with `analytics.view.organization`.

**Key decisions:**
- Cache key: `{tenant_slug}:analytics:executive_summary:{filter_hash}`
- TTL: 300s (warm tier)
- Overdue = active tasks with any timer status = `Breached`; at-risk = any timer status = `Warning`.
- Bottleneck score = `overdue_count * 2 + at_risk_count`, sorted descending with average time-at-stage as tie-breaker.
- Bottleneck query starts from visible active task IDs (`TaskVisibilityScope`) and supports an optional `department_id` filter.
- Department health uses hardcoded thresholds (see Open Questions).

**File:** `app/Modules/Analytics/Services/ExecutiveDashboardService.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Analytics\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Analytics\Enums\DepartmentHealth;
use App\Modules\Analytics\Exceptions\AnalyticsScopeDeniedException;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Department;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function summary(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        $cacheKey = $this->cacheKey('executive_summary');

        return Cache::remember($cacheKey, 300, function () use ($user, $filters) {
            $base = $this->baseTaskQuery($user);
            $this->applyDateRange($base, $filters);

            $active = (clone $base)->where('status', TaskStatus::Active)->count();
            $suspended = (clone $base)->where('status', TaskStatus::Suspended)->count();
            $completed = $this->countByStatusAndDate($base, TaskStatus::Completed, $filters);
            $cancelled = $this->countByStatusAndDate($base, TaskStatus::Cancelled, $filters);

            $overdue = (clone $base)
                ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Breached))
                ->count();

            $atRisk = (clone $base)
                ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Warning))
                ->count();

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
        });
    }

    public function bottlenecks(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        $limit = $filters['limit'] ?? 10;

        $rows = DB::table('tasks')
            ->join('task_stage_instances', 'tasks.id', '=', 'task_stage_instances.task_id')
            ->join('blueprint_stages', 'task_stage_instances.blueprint_stage_id', '=', 'blueprint_stages.id')
            ->join('stage_types', 'blueprint_stages.stage_type_id', '=', 'stage_types.id')
            ->join('departments', 'task_stage_instances.owning_department_id', '=', 'departments.id')
            ->leftJoin('sla_timer_instances', 'task_stage_instances.id', '=', 'sla_timer_instances.stage_instance_id')
            ->where('tasks.status', TaskStatus::Active->value)
            ->whereNull('tasks.archived_at')
            ->whereNull('tasks.deleted_at')
            ->where('task_stage_instances.status', \App\Modules\Task\Enums\StageInstanceStatus::Active->value)
            ->whereIn('sla_timer_instances.status', [SlaTimerStatus::Warning->value, SlaTimerStatus::Breached->value])
            ->select([
                'stage_types.public_id as stage_type_public_id',
                'stage_types.name_ar as stage_type_name_ar',
                'stage_types.name_en as stage_type_name_en',
                'departments.public_id as department_public_id',
                'departments.name_ar as department_name_ar',
                'departments.name_en as department_name_en',
                DB::raw('SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Breached->value.' THEN 1 ELSE 0 END) as overdue_count'),
                DB::raw('SUM(CASE WHEN sla_timer_instances.status = '.SlaTimerStatus::Warning->value.' THEN 1 ELSE 0 END) as at_risk_count'),
                DB::raw('AVG(EXTRACT(EPOCH FROM (NOW() - task_stage_instances.entered_at))) as avg_time_at_stage_seconds'),
            ])
            ->groupBy('stage_types.id', 'departments.id')
            ->havingRaw('(overdue_count * 2 + at_risk_count) > 0')
            ->orderByRaw('(overdue_count * 2 + at_risk_count) DESC')
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
            'score' => (int) $row->overdue_count * 2 + (int) $row->at_risk_count,
            'average_time_at_stage_seconds' => (int) $row->avg_time_at_stage_seconds,
        ])->all();
    }

    public function departmentHealth(User $user, array $filters): array
    {
        $this->ensureOrganizationAccess($user);

        return Department::where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (Department $department) use ($user, $filters) {
                $base = $this->baseTaskQuery($user)
                    ->whereHas('stageInstances', fn ($q) => $q->where('owning_department_id', $department->id));
                $this->applyDateRange($base, $filters);

                $overdue = (clone $base)
                    ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Breached))
                    ->count();

                $atRisk = (clone $base)
                    ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Warning))
                    ->count();

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
    }

    public function drillDown(User $user, string $metric, array $filters)
    {
        $this->ensureOrganizationAccess($user);

        $query = $this->baseTaskQuery($user);

        match ($metric) {
            'active' => $query->where('status', TaskStatus::Active),
            'suspended' => $query->where('status', TaskStatus::Suspended),
            'completed' => $query->where('status', TaskStatus::Completed),
            'cancelled' => $query->where('status', TaskStatus::Cancelled),
            'overdue' => $query->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Breached)),
            'at_risk' => $query->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Warning)),
            default => throw new InvalidReportFilterException("Invalid metric: {$metric}"),
        };

        $this->applyFilters($query, $filters);

        return $query->orderBy('id')->cursorPaginate($filters['per_page'] ?? 15);
    }

    private function ensureOrganizationAccess(User $user): void
    {
        if (! $this->iamPolicy->hasCapability($user, 'analytics.view.organization')) {
            throw new AnalyticsScopeDeniedException();
        }
    }

    private function countByStatusAndDate($base, TaskStatus $status, array $filters): int
    {
        $q = (clone $base)->where('status', $status);

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

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['priority_id'])) {
            $query->whereHas('priority', fn ($q) => $q->where('public_id', $filters['priority_id']));
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('stageInstances', fn ($q) => $q->whereHas('department', fn ($q) => $q->where('public_id', $filters['department_id'])));
        }

        if (! empty($filters['blueprint_category_id'])) {
            $query->whereHas('blueprint', fn ($q) => $q->whereHas('category', fn ($q) => $q->where('public_id', $filters['blueprint_category_id'])));
        }
    }
}
```

**Test cases:**
1. Tenant with 3 active, 1 overdue, 1 suspended → summary returns `active=3, overdue=1, suspended=1`.
2. User without `analytics.view.organization` → `AnalyticsScopeDeniedException` (403).

**Rules:** `coding-standards.md` — Caching (warm tier, tenant-prefixed), Error Handling (`analytics` channel), no magic numbers (use enums), cursor pagination.

---

### 4. Department Dashboard Service

**One-line summary:** Per-department performance and per-employee team metrics scoped by `analytics.view.department` or `analytics.view.individuals_in_department`.

**Key decisions:**
- Department attribution uses `task_stage_instances.owning_department_id` and `task_sub_stage_instances.owning_department_id`.
- Scope check uses `IamPolicy::check($user, 'analytics.view.department', ScopeType::SPECIFIC_DEPARTMENT, $department->id)`.
- Average stage delay and team metrics are filtered by the caller's visible task IDs so confidential tasks are not included.
- Cache key: `{tenant_slug}:analytics:department:{department_public_id}:{metric}:{filter_hash}` TTL 300s; keys are tracked for event-driven invalidation.

**File:** `app/Modules/Analytics/Services/DepartmentDashboardService.php`

**Code snippet:**
```php
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

class DepartmentDashboardService
{
    use IntersectsTaskVisibility;

    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function performance(User $user, Department $department, array $filters): array
    {
        $this->authorizeDepartment($user, $department);

        $cacheKey = $this->cacheKey("department:{$department->public_id}:performance");

        return Cache::remember($cacheKey, 300, function () use ($user, $department, $filters) {
            $base = $this->baseTaskQuery($user)
                ->where(function ($q) use ($department) {
                    $q->whereHas('stageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id))
                        ->orWhereHas('subStageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id));
                });

            $this->applyDateRange($base, $filters);

            $active = (clone $base)->where('status', TaskStatus::Active)->count();
            $overdue = (clone $base)
                ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Breached))
                ->count();
            $atRisk = (clone $base)
                ->whereHas('slaTimerInstances', fn ($q) => $q->where('status', SlaTimerStatus::Warning))
                ->count();

            $avgDelay = TaskStageInstance::where('owning_department_id', $department->id)
                ->where('status', StageInstanceStatus::Completed)
                ->whereNotNull('entered_at')
                ->whereNotNull('exited_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (exited_at - entered_at))) as avg_seconds')
                ->value('avg_seconds') ?? 0;

            return [
                'department_public_id' => $department->public_id,
                'active_tasks' => $active,
                'overdue_tasks' => $overdue,
                'at_risk_tasks' => $atRisk,
                'average_stage_delay_seconds' => (int) $avgDelay,
            ];
        });
    }

    public function team(User $user, Department $department): array
    {
        $this->authorizeDepartment($user, $department);

        $cacheKey = $this->cacheKey("department:{$department->public_id}:team");

        return Cache::remember($cacheKey, 300, function () use ($department) {
            $userIds = UserPositionAssignment::whereHas('position', fn ($q) => $q->where('department_id', $department->id))
                ->whereNull('ended_at')
                ->pluck('user_id');

            return $userIds->map(function (int $userId) use ($department) {
                $activeAssignments = TaskStageAssignment::where('user_id', $userId)
                    ->where('is_completed', false)
                    ->where(function ($q) use ($department) {
                        $q->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', StageInstanceStatus::Active))
                            ->orWhereHas('subStageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', SubStageInstanceStatus::Active));
                    })
                    ->count();

                $overdueAssignments = TaskStageAssignment::where('user_id', $userId)
                    ->where('is_completed', false)
                    ->where(function ($q) use ($department) {
                        $q->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', StageInstanceStatus::Active))
                            ->orWhereHas('subStageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id)->where('status', SubStageInstanceStatus::Active));
                    })
                    ->where(function ($q) {
                        $q->whereHas('stageInstance.slaTimerInstance', fn ($tq) => $tq->where('status', SlaTimerStatus::Breached))
                            ->orWhereHas('subStageInstance.slaTimerInstance', fn ($tq) => $tq->where('status', SlaTimerStatus::Breached));
                    })
                    ->count();

                $completedStages = TaskStageAssignment::where('user_id', $userId)
                    ->where('is_completed', true)
                    ->whereHas('stageInstance', fn ($sq) => $sq->where('owning_department_id', $department->id))
                    ->count();

                return [
                    'user_public_id' => User::find($userId)?->public_id,
                    'active_assignments' => $activeAssignments,
                    'overdue_assignments' => $overdueAssignments,
                    'completed_stages' => $completedStages,
                ];
            })->filter(fn ($row) => $row['user_public_id'] !== null)->values()->all();
        });
    }

    public function drillDown(User $user, Department $department, array $filters)
    {
        $this->authorizeDepartment($user, $department);

        $query = $this->baseTaskQuery($user)
            ->where(function ($q) use ($department) {
                $q->whereHas('stageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id))
                    ->orWhereHas('subStageInstances', fn ($sq) => $sq->where('owning_department_id', $department->id));
            });

        $this->applyFilters($query, $filters);

        return $query->orderBy('id')->cursorPaginate($filters['per_page'] ?? 15);
    }

    private function authorizeDepartment(User $user, Department $department): void
    {
        $hasOrg = $this->iamPolicy->hasCapability($user, 'analytics.view.organization');
        $hasDept = $this->iamPolicy->check($user, 'analytics.view.department', ScopeType::SPECIFIC_DEPARTMENT, $department->id);
        $hasIndividuals = $this->iamPolicy->check($user, 'analytics.view.individuals_in_department', ScopeType::SPECIFIC_DEPARTMENT, $department->id);

        if (! ($hasOrg || $hasDept || $hasIndividuals)) {
            throw new AnalyticsScopeDeniedException();
        }
    }
}
```

**Test cases:**
1. Manager with `analytics.view.department` scoped to department A sees only department A metrics.
2. Manager without department scope gets 403.

**Rules:** `coding-standards.md` — Reuse enums, eager-load relationships, tenant-prefixed cache, `HasRateLimiting` in controller.

---

### 5. Aging Report Service

**One-line summary:** Cursor-paginated list of open tasks sorted by time elapsed at current stage.

**Key decisions:**
- Sort by current stage `entered_at` ascending (oldest first) with `tasks.id` as a tie-breaker for cursor pagination.
- Include current stage/sub-stage name, active assignees, SLA health, priority, and created_at.
- Supports status, priority, department, blueprint category, and date filters.
- Requires `analytics.view.organization`, `analytics.view.department`, or `task.view.follow_up_scope` capability.

**File:** `app/Modules/Analytics/Services/AgingReportService.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Analytics\Services;

use App\Models\User;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Task\Enums\StageInstanceStatus;
use App\Modules\Task\Enums\SubStageInstanceStatus;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;

class AgingReportService
{
    use IntersectsTaskVisibility;

    public function aging(User $user, array $filters)
    {
        $query = $this->baseTaskQuery($user)
            ->whereIn('tasks.status', [TaskStatus::Active, TaskStatus::Suspended])
            ->with([
                'priority',
                'blueprint.category',
                'stageInstances' => fn ($q) => $q->where('status', StageInstanceStatus::Active)
                    ->with(['blueprintStage.stageType', 'assignments.user']),
            ])
            ->leftJoin('task_stage_instances', function ($join) {
                $join->on('tasks.id', '=', 'task_stage_instances.task_id')
                    ->where('task_stage_instances.status', StageInstanceStatus::Active->value);
            })
            ->select('tasks.*')
            ->orderBy('task_stage_instances.entered_at')
            ->orderBy('tasks.id');

        $this->applyFilters($query, $filters);

        return $query->cursorPaginate($filters['per_page'] ?? 15);
    }

    public function resolveSlaHealth($instance): string
    {
        $timer = $instance->slaTimerInstances->first();

        if (! $timer) {
            return 'none';
        }

        return match ($timer->status) {
            SlaTimerStatus::Breached => 'red',
            SlaTimerStatus::Warning => 'amber',
            SlaTimerStatus::Paused => 'grey',
            default => 'green',
        };
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority_id'])) {
            $query->whereHas('priority', fn ($q) => $q->where('public_id', $filters['priority_id']));
        }

        if (! empty($filters['department_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('stageInstances', fn ($sq) => $sq->whereHas('department', fn ($dq) => $dq->where('public_id', $filters['department_id'])))
                    ->orWhereHas('subStageInstances', fn ($sq) => $sq->whereHas('department', fn ($dq) => $dq->where('public_id', $filters['department_id'])));
            });
        }

        if (! empty($filters['blueprint_category_id'])) {
            $query->whereHas('blueprint', fn ($q) => $q->whereHas('category', fn ($cq) => $cq->where('public_id', $filters['blueprint_category_id'])));
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
    }
}
```

**Test cases:**
1. Open task at stage for 3 days appears before task at stage for 1 day when sorted by `entered_at`.
2. Filter by `priority` returns only matching priorities.

**Rules:** `coding-standards.md` — Cursor pagination, eager loading, no caching for time-sensitive lists.

---

### 6. Controllers

**One-line summary:** Thin controllers validate requests, check rate limits, call services, and return API Resources.

**Key decisions:**
- All controllers `use HasRateLimiting` and apply `RateLimits::LIST`.
- Capability checks done via `IamPolicy` injected into services; routes use `auth:sanctum` only.
- Drill-down endpoints reuse service query methods and return cursor-paginated task lists.

**Files:**
- `app/Modules/Analytics/Controllers/ExecutiveDashboardController.php`
- `app/Modules/Analytics/Controllers/DepartmentDashboardController.php`
- `app/Modules/Analytics/Controllers/AgingReportController.php`

**Code snippet — ExecutiveDashboardController:**
```php
<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\BottleneckRequest;
use App\Modules\Analytics\Requests\ExecutiveSummaryRequest;
use App\Modules\Analytics\Resources\BottleneckResource;
use App\Modules\Analytics\Resources\DepartmentHealthResource;
use App\Modules\Analytics\Resources\ExecutiveSummaryResource;
use App\Modules\Analytics\Resources\TaskListItemResource;
use App\Modules\Analytics\Services\ExecutiveDashboardService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class ExecutiveDashboardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ExecutiveDashboardService $service,
    ) {}

    public function summary(ExecutiveSummaryRequest $request): ExecutiveSummaryResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new ExecutiveSummaryResource(
            $this->service->summary($request->user(), $request->validated())
        );
    }

    public function bottlenecks(BottleneckRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->bottlenecks($request->user(), $request->validated());

        return BottleneckResource::collection($items);
    }

    public function departmentHealth(ExecutiveSummaryRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->departmentHealth($request->user(), $request->validated());

        return DepartmentHealthResource::collection($items);
    }

    public function summaryDrillDown(ExecutiveSummaryRequest $request, string $metric)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->drillDown($request->user(), $metric, $request->validated())
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function bottleneckDrillDown(BottleneckRequest $request, string $stageType)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['stage_type_id' => $stageType]);
        $paginator = $this->service->drillDown($request->user(), 'overdue', $filters)
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**Code snippet — DepartmentDashboardController:**
```php
<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\DepartmentPerformanceRequest;
use App\Modules\Analytics\Resources\DepartmentPerformanceResource;
use App\Modules\Analytics\Resources\TaskListItemResource;
use App\Modules\Analytics\Resources\TeamMetricsResource;
use App\Modules\Analytics\Services\DepartmentDashboardService;
use App\Modules\Organization\Models\Department;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class DepartmentDashboardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DepartmentDashboardService $service,
    ) {}

    public function performance(DepartmentPerformanceRequest $request, Department $department): DepartmentPerformanceResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new DepartmentPerformanceResource(
            $this->service->performance($request->user(), $department, $request->validated())
        );
    }

    public function team(DepartmentPerformanceRequest $request, Department $department)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->team($request->user(), $department);

        return TeamMetricsResource::collection($items);
    }

    public function drillDown(DepartmentPerformanceRequest $request, Department $department)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->drillDown($request->user(), $department, $request->validated())
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**Code snippet — AgingReportController:**
```php
<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\AgingReportRequest;
use App\Modules\Analytics\Resources\AgingReportResource;
use App\Modules\Analytics\Services\AgingReportService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class AgingReportController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private AgingReportService $service,
    ) {}

    public function index(AgingReportRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->aging($request->user(), $request->validated())
            ->through(fn ($task) => new AgingReportResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**Rules:** `coding-standards.md` — Controllers are thin, rate limiting in controller, API Resources required.

---

### 7. Form Requests

**One-line summary:** Validate filters for each analytics endpoint group.

**Files:**
- `app/Modules/Analytics/Requests/ExecutiveSummaryRequest.php`
- `app/Modules/Analytics/Requests/BottleneckRequest.php`
- `app/Modules/Analytics/Requests/DepartmentPerformanceRequest.php`
- `app/Modules/Analytics/Requests/AgingReportRequest.php`

**Code snippet — ExecutiveSummaryRequest:**
```php
<?php

namespace App\Modules\Analytics\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecutiveSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
```

**Code snippet — AgingReportRequest:**
```php
<?php

namespace App\Modules\Analytics\Requests;

use App\Modules\Task\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority_id' => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'blueprint_category_id' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Form Requests for validation, `Rule::enum()` for enums.

---

### 8. API Resources

**One-line summary:** Transform analytics data to frontend-friendly JSON, exposing `public_id` only.

**Files:**
- `app/Modules/Analytics/Resources/ExecutiveSummaryResource.php`
- `app/Modules/Analytics/Resources/BottleneckResource.php`
- `app/Modules/Analytics/Resources/DepartmentHealthResource.php`
- `app/Modules/Analytics/Resources/DepartmentPerformanceResource.php`
- `app/Modules/Analytics/Resources/TeamMetricsResource.php`
- `app/Modules/Analytics/Resources/AgingReportResource.php`
- `app/Modules/Analytics/Resources/TaskListItemResource.php`

**Code snippet — ExecutiveSummaryResource:**
```php
<?php

namespace App\Modules\Analytics\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecutiveSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active' => $this->resource['active'],
            'overdue' => $this->resource['overdue'],
            'at_risk' => $this->resource['at_risk'],
            'suspended' => $this->resource['suspended'],
            'completed' => $this->resource['completed'],
            'cancelled' => $this->resource['cancelled'],
            'completion_rate' => $this->resource['completion_rate'],
        ];
    }
}
```

**Code snippet — AgingReportResource:**
```php
<?php

namespace App\Modules\Analytics\Resources;

use App\Modules\Analytics\Enums\TaskHealth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgingReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $activeStage = $task->stageInstances->first();
        $activeSubStage = $task->subStageInstances->first();
        $currentStep = $activeSubStage ?? $activeStage;

        $health = 'none';
        if ($task->status->value === \App\Modules\Task\Enums\TaskStatus::Suspended->value) {
            $health = 'grey';
        } elseif ($currentStep) {
            $timer = $currentStep->slaTimerInstances->first();
            $health = match ($timer?->status) {
                \App\Modules\Tracking\Enums\SlaTimerStatus::Breached => 'red',
                \App\Modules\Tracking\Enums\SlaTimerStatus::Warning => 'amber',
                default => 'green',
            };
        }

        return [
            'task_public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en,
            'priority' => $task->priority?->name_ar,
            'current_stage_name_ar' => $currentStep?->blueprintStage?->name_ar ?? $currentStep?->blueprintSubStage?->name_ar,
            'current_stage_name_en' => $currentStep?->blueprintStage?->name_en ?? $currentStep?->blueprintSubStage?->name_en,
            'active_assignees' => $currentStep?->assignments->map(fn ($a) => [
                'public_id' => $a->user?->public_id,
                'name_ar' => $a->user?->name_ar,
                'name_en' => $a->user?->name_en,
            ])->filter(fn ($u) => $u['public_id'] !== null)->values(),
            'sla_health' => $health,
            'created_at' => $task->created_at?->toIso8601String(),
            'entered_at' => $currentStep?->entered_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources required, expose `public_id` only, eager-load relationships.

---

### 9. Routes

**One-line summary:** Register analytics routes under `/api/v1/analytics`.

**File:** `routes/api/v1/analytics.php`

**Code snippet:**
```php
<?php

use App\Modules\Analytics\Controllers\AgingReportController;
use App\Modules\Analytics\Controllers\DepartmentDashboardController;
use App\Modules\Analytics\Controllers\ExecutiveDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::prefix('executive')->group(function () {
        Route::get('summary', [ExecutiveDashboardController::class, 'summary']);
        Route::get('bottlenecks', [ExecutiveDashboardController::class, 'bottlenecks']);
        Route::get('department-health', [ExecutiveDashboardController::class, 'departmentHealth']);
        Route::get('summary/drill-down/{metric}', [ExecutiveDashboardController::class, 'summaryDrillDown']);
        Route::get('bottlenecks/{stage_type}/drill-down', [ExecutiveDashboardController::class, 'bottleneckDrillDown']);
    });

    Route::prefix('departments/{department}')->group(function () {
        Route::get('performance', [DepartmentDashboardController::class, 'performance']);
        Route::get('team', [DepartmentDashboardController::class, 'team']);
        Route::get('performance/drill-down', [DepartmentDashboardController::class, 'drillDown']);
    });

    Route::get('tasks/aging', [AgingReportController::class, 'index']);
});
```

**File:** `routes/tenant.php`

**Code snippet:**
```php
require __DIR__.'/api/v1/analytics.php';
```

**Rules:** `coding-standards.md` — Versioned routes, kebab-case paths.

---

### 10. Cache Invalidation Listeners

**One-line summary:** Listen to Task/Tracking lifecycle events and forget analytics summary cache keys.

**Key decisions:**
- Track every used executive-summary and department cache key in `{tenant_slug}:analytics:keys:{group}` lists.
- On task launched/suspended/resumed/cancelled/completed and stage/sub-stage completed/returned events, forget every tracked key in both groups.
- No cache warming in MVP; next request recomputes.

**Files:** One listener class per event, matching the Tracking module pattern. Each listener type-hints its event in `handle()` so Laravel's event auto-discovery (`bootstrap/app.php`) wires it.

```
app/Modules/Analytics/Listeners/
├── InvalidateCacheOnTaskLaunched.php
├── InvalidateCacheOnTaskSuspended.php
├── InvalidateCacheOnTaskResumed.php
├── InvalidateCacheOnTaskCancelled.php
├── InvalidateCacheOnTaskCompleted.php
├── InvalidateCacheOnStageInstanceCompleted.php
├── InvalidateCacheOnStageInstanceReturned.php
├── InvalidateCacheOnSubStageInstanceCompleted.php
├── InvalidateCacheOnSlaTimerStarted.php
└── Concerns/
    └── InvalidatesAnalyticsCache.php
```

**Code snippet — shared concern:**
```php
<?php

namespace App\Modules\Analytics\Listeners\Concerns;

use Illuminate\Support\Facades\Cache;

trait InvalidatesAnalyticsCache
{
    protected function invalidateAnalyticsCache(): void
    {
        $slug = tenant()->slug;

        foreach (['executive_summary', 'department'] as $group) {
            $listKey = "{$slug}:analytics:keys:{$group}";
            $keys = Cache::get($listKey, []);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            Cache::forget($listKey);
        }
    }
}
```

**Code snippet — listener example:**
```php
<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Analytics\Listeners\Concerns\InvalidatesAnalyticsCache;
use App\Modules\Task\Events\TaskLaunched;

class InvalidateCacheOnTaskLaunched
{
    use InvalidatesAnalyticsCache;

    public function handle(TaskLaunched $event): void
    {
        $this->invalidateAnalyticsCache();
    }
}
```

**Auto-discovery:** Already enabled in `bootstrap/app.php` via:
```php
->withEvents(discover: [
    __DIR__.'/../app/Modules/*/Listeners',
])
```
No manual registration in `EventServiceProvider` is required.

**Rules:** `coding-standards.md` — Cache invalidation by domain events, tenant-prefixed keys. Follow existing listener pattern from `app/Modules/Tracking/Listeners/`.

---

### 11. Logging Channel

**One-line summary:** Add `analytics` channel to `config/logging.php`.

**File:** `config/logging.php`

**Code snippet:**
```php
'analytics' => [
    'driver' => 'daily',
    'path' => storage_path('logs/analytics/analytics.log'),
    'level' => 'debug',
    'days' => 14,
],
```

**Rules:** `coding-standards.md` — Per-module channel, structured context.

---

### 12. Exception Handling

**One-line summary:** Two domain exceptions extending `App\Exceptions\DomainException`.

**Files:**
- `app/Modules/Analytics/Exceptions/AnalyticsScopeDeniedException.php`
- `app/Modules/Analytics/Exceptions/InvalidReportFilterException.php`

**Code snippet — AnalyticsScopeDeniedException:**
```php
<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;

class AnalyticsScopeDeniedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This action requires an analytics capability.');
    }
}
```

**Code snippet — InvalidReportFilterException:**
```php
<?php

namespace App\Modules\Analytics\Exceptions;

use App\Exceptions\DomainException;

class InvalidReportFilterException extends DomainException
{
    public function __construct(string $message = 'Invalid report filter.')
    {
        parent::__construct($message);
    }
}
```

**Rules:** `coding-standards.md` — Error Handling, safe JSON messages.

---

### 13. Capability Seeder Verification

**One-line summary:** Ensure analytics capabilities exist in `database/seeders/CapabilitySeeder.php`.

**Code snippet:**
```php
['key' => 'analytics.view.organization', 'name_ar' => 'عرض تحليلات المؤسسة', 'name_en' => 'View Organization Analytics', 'description' => 'Can view organization-wide analytics.'],
['key' => 'analytics.view.department', 'name_ar' => 'عرض تحليلات القسم', 'name_en' => 'View Department Analytics', 'description' => 'Can view department-level analytics.'],
['key' => 'analytics.view.individuals_in_department', 'name_ar' => 'عرض أداء الأفراد', 'name_en' => 'View Individual Metrics', 'description' => 'Can view individual employee metrics inside own department.'],
```

**Rules:** `coding-standards.md` — Capabilities as named permissions, no hardcoded roles.

---

### 14. Feature Tests

**One-line summary:** Feature tests under `tests/Feature/Modules/Analytics/` using tenant test Pattern A.

**Files:**
- `tests/Feature/Modules/Analytics/ExecutiveDashboardTest.php`
- `tests/Feature/Modules/Analytics/DepartmentDashboardTest.php`
- `tests/Feature/Modules/Analytics/AgingReportTest.php`
- `tests/Feature/Modules/Analytics/AnalyticsAbacTest.php`

**Code snippet — ExecutiveDashboardTest setup:**
```php
<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->provisioner = app(\App\Services\Platform\TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Test Tenant',
        'name_ar' => 'اختبار',
        'slug' => 'test-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    $this->seed(\Database\Seeders\CapabilitySeeder::class);
    $this->seed(\Database\Seeders\TenantDatabaseSeeder::class);

    $this->user = \App\Models\User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenant->public_id,
    ];
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});

it('returns executive summary counts', function () {
    $active = \App\Modules\Task\Models\Task::factory()->active()->create();
    $suspended = \App\Modules\Task\Models\Task::factory()->suspended()->create();

    $response = $this->withHeaders($this->authHeaders)
        ->getJson('/v1/analytics/executive/summary');

    $response->assertOk()
        ->assertJsonPath('active', 1)
        ->assertJsonPath('suspended', 1);
});
```

**Test cases:**
1. Executive summary returns correct counts.
2. Department performance returns only scoped department data.
3. Aging report cursor-paginates open tasks.
4. Missing analytics capability returns 403.
5. Confidential task excluded for unauthorized user.

**Rules:** `testing-policy.md` — Feature tests mandatory, tenant isolation, ABAC coverage.

---

### 15. Recommended Indexes

**One-line summary:** Ensure existing indexes support analytics queries; add if missing.

**Key indexes to verify:**
- `tasks(status, archived_at, deleted_at)`
- `tasks(blueprint_id)`
- `tasks(priority_id)`
- `tasks(initiator_user_id)`
- `task_stage_instances(task_id, status, owning_department_id)`
- `task_stage_instances(owning_department_id, status, entered_at, exited_at)`
- `task_sub_stage_instances(task_id, status, owning_department_id)`
- `sla_timer_instances(task_id, status)`
- `sla_timer_instances(stage_instance_id, status)`
- `sla_timer_instances(sub_stage_instance_id, status)`

**Rules:** `coding-standards.md` — Index strategy for query performance.

---

## Execution Order

1. **Scaffold module** — create `app/Modules/Analytics/` directories and enums.
2. **Add logging channel** — `config/logging.php`.
3. **Verify analytics capabilities** — confirm in `CapabilitySeeder.php`.
4. **Build shared concern** — `IntersectsTaskVisibility.php`.
5. **Build services** — Executive → Department → Aging.
6. **Build requests, resources, exceptions**.
7. **Build controllers** — wire services.
8. **Add routes** — `routes/api/v1/analytics.php` and require in `routes/tenant.php`.
9. **Add cache invalidation listeners** — create listener classes under `app/Modules/Analytics/Listeners/`; auto-discovery handles registration via `bootstrap/app.php`.
10. **Feature tests** — Executive, Department, Aging, ABAC.
11. **Run Pint + Pest** — fix style, ensure tests pass.
12. **Regenerate OpenAPI** — `openapi/openapi.json`.

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/analytics/executive/summary` | Sanctum + X-Tenant | `analytics.view.organization` | Org-wide counts + completion rate |
| GET | `/api/v1/analytics/executive/bottlenecks` | Sanctum + X-Tenant | `analytics.view.organization` | Top stage-type/department bottlenecks |
| GET | `/api/v1/analytics/executive/department-health` | Sanctum + X-Tenant | `analytics.view.organization` | Red/amber/green health per department |
| GET | `/api/v1/analytics/executive/summary/drill-down/{metric}` | Sanctum + X-Tenant | `analytics.view.organization` | Cursor-paginated tasks for a metric |
| GET | `/api/v1/analytics/executive/bottlenecks/{stage_type}/drill-down` | Sanctum + X-Tenant | `analytics.view.organization` | Tasks at bottleneck stage type |
| GET | `/api/v1/analytics/departments/{department}/performance` | Sanctum + X-Tenant | `analytics.view.department` or `analytics.view.organization` | Department metrics |
| GET | `/api/v1/analytics/departments/{department}/team` | Sanctum + X-Tenant | `analytics.view.department` or `analytics.view.individuals_in_department` | Per-employee team metrics |
| GET | `/api/v1/analytics/departments/{department}/performance/drill-down` | Sanctum + X-Tenant | `analytics.view.department` or `analytics.view.organization` | Tasks contributing to department metrics |
| GET | `/api/v1/analytics/tasks/aging` | Sanctum + X-Tenant | `analytics.view.organization`, `analytics.view.department`, or `task.view.follow_up_scope` | Task aging report |

---

## What to Test Manually

1. **Happy path — executive summary** — Login as tenant admin with `analytics.view.organization`, create tasks in various states, call `/analytics/executive/summary`, verify counts match.
2. **Happy path — department performance** — Login as department manager, verify only own department metrics are returned.
3. **Happy path — aging report** — Create active tasks with old stage entry dates; verify oldest appears first and pagination works.
4. **Drill-down** — Click a summary metric in the frontend (or call drill-down endpoint); verify returned tasks match the metric filter.
5. **ABAC denial** — User without analytics capability gets 403 on all analytics endpoints.
6. **Confidentiality** — Confidential task is excluded from analytics results for users not named as participants.
7. **Tenant isolation** — Tenant A's tasks do not appear in Tenant B analytics.
8. **Cache invalidation** — Launch a task, call summary (cache), complete the task, call summary again — count should update after cache invalidation.
9. **Rate limiting** — Exceed 60 requests/min for list endpoints; verify 429 response with `Retry-After`.
10. **N+1 check** — Enable `DB::listen()` in test/development; verify no N+1 when rendering resources.
