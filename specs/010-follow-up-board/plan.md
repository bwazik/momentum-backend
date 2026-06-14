# Implementation Plan: 010 Follow-Up Board & Tracking API

> **Spec:** 010-follow-up-board
> **Date:** 2026-06-14
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| `FollowUpActionType` tenant-configurable? | **No.** Use fixed 5-case enum: `PhoneCall`, `Message`, `Meeting`, `Email`, `Other`. | Covers MVP follow-up scenarios; lookup-table extension is V2. |
| `external_reference` exact or partial match? | **Exact match** on `reference_number`. | Reference numbers are structured identifiers; partial match deferred to Search module (Spec 011). Until Spec 014 is implemented, the filter returns 422. |
| Bottleneck scoring formula? | **Reuse Analytics formula:** `score = overdue_count × 2 + at_risk_count`, sorted descending. | Keeps cross-module definition consistent. |
| Follow-up action emits domain event? | **Yes.** Emit `FollowUpActionCreated` implementing `ShouldDispatchAfterCommit` for future Audit (Spec 015). | Provides reliable audit trail. |
| Module location? | **New bounded context `app/Modules/FollowUp/`.** | Follow-up owns `follow_up_actions`, reads Task/Tracking, mirrors Analytics pattern. |
| Time-at-stage excludes non-working time? | **Yes.** Use `WorkingDayCalculator::workingSecondsBetween()` with the tenant's default working calendar. | `departments.working_calendar_id` is deferred, so tenant default is the stable fallback used by SLA timers. |
| `sort_direction` enum? | **Yes.** Create `BoardSortDirection` string-backed enum with `Asc = 'asc'`, `Desc = 'desc'` so `Rule::enum()` can validate it. | Aligns with spec's enum-validation requirement. |
| `BoardSortField` int-backed or string-backed? | **String-backed.** Values `priority`, `due_date`, `created_at`, `time_at_stage`, `department`, `stage_type` match the API contract and frontend expectations. | Integer-backed enum would have required clients to send numeric IDs, contradicting the spec. |
| At-risk list sort? | **By `sla_timer_instances.deadline_at ASC`.** Join the active stage's warning timer and order by soonest deadline (least time remaining first). | `entered_at` is not a reliable proxy when SLA durations vary across Blueprints. |
| Bottleneck aggregation approach? | **PHP aggregation from raw rows.** Query selects row-level `is_breached`/`is_at_risk` flags; PHP groups by stage type + department, sums flags, computes score, and averages working seconds via `WorkingDayCalculator`. | Needed to use working-calendar-aware averages instead of raw wall-clock SQL aggregates, while keeping counts accurate. |
| Bottleneck cache key scope? | **Per-user + per-filter.** Key includes `tenant_slug`, user `public_id`, `department_id`, and `limit`. | Prevents cross-user ABAC leakage when an org-wide viewer's cached result could be served to a follow-up-scope viewer. |

---

## Technical Approach

Create a new `FollowUp` module under `app/Modules/FollowUp/`. It is a read-heavy operational layer that reuses `TaskVisibilityScope` and the existing `IntersectsTaskVisibility` concern from Analytics for ABAC/confidentiality filtering. The module owns one new table (`follow_up_actions`) and provides board, overdue, at-risk, bottleneck, and action-log endpoints. All list endpoints are cursor-paginated; bottleneck is a bounded scalar list and may be cached. Time-at-stage and SLA health are computed on the paginated result set to keep the base query fast and deterministic.

**Key decisions:**
- **Reuse `IntersectsTaskVisibility`** — guarantees identical ABAC/confidentiality rules as Analytics and Task list.
- **Compute enrichment after pagination** — SLA health and working-seconds elapsed are calculated in PHP for the small page of results, avoiding heavy per-row functions in the main query.
- **Sort proxy for `time_at_stage`** — DB orders by the active stage `entered_at` with inverted direction so `time_at_stage` descending maps to oldest-entered-first (longest wait).
- **At-risk sort by deadline** — join the active stage's warning timer and order by `deadline_at ASC` so the list is sorted by least time remaining.
- **Bottleneck PHP aggregation** — query returns raw rows with `is_breached`/`is_at_risk` flags; PHP groups by stage type + department, sums flags for accurate counts, computes the weighted score, and averages working seconds via `WorkingDayCalculator`.
- **Bottleneck cache per-user + per-filter** — cached 300s at `{tenant_slug}:followup:bottlenecks:{user_public_id}:{department_id}:{limit}`, invalidated by existing Task/Tracking lifecycle events.
- **No queue jobs** — all endpoints are synchronous queries; only the follow-up action creation emits a `ShouldDispatchAfterCommit` event.

---

## Affected Modules / Files

### New Files

```
app/Modules/FollowUp/
├── Controllers/
│   ├── FollowUpBoardController.php
│   └── FollowUpActionController.php
├── Services/
│   ├── FollowUpBoardService.php
│   ├── FollowUpActionService.php
│   └── Concerns/
│       └── EnrichesBoardTasks.php
├── Enums/
│   ├── FollowUpActionType.php
│   ├── SlaHealth.php
│   ├── BoardSortField.php
│   └── BoardSortDirection.php
├── Models/
│   └── FollowUpAction.php
├── Requests/
│   ├── BoardRequest.php
│   ├── StoreFollowUpActionRequest.php
│   └── ListFollowUpActionsRequest.php
├── Resources/
│   ├── BoardTaskResource.php
│   ├── BottleneckResource.php
│   └── FollowUpActionResource.php
├── Events/
│   └── FollowUpActionCreated.php
├── Exceptions/
│   ├── FollowUpActionNotAllowedException.php
│   └── InvalidBoardFilterException.php
├── Listeners/
│   ├── Concerns/
│   │   └── InvalidatesFollowUpBottleneckCache.php
│   ├── InvalidateBottleneckOnStageCompleted.php
│   ├── InvalidateBottleneckOnStageAdvanced.php
│   ├── InvalidateBottleneckOnStageReturned.php
│   ├── InvalidateBottleneckOnSubStageCompleted.php
│   ├── InvalidateBottleneckOnSlaWarning.php
│   └── InvalidateBottleneckOnSlaBreach.php
database/migrations/tenant/2026_06_14_000001_create_follow_up_actions_table.php
database/factories/FollowUpActionFactory.php
routes/api/v1/follow-up.php
tests/Feature/Modules/FollowUp/
├── FollowUpBoardTest.php
└── FollowUpActionTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `config/logging.php` | Add `followup` daily log channel. |
| `routes/tenant.php` | `require __DIR__.'/api/v1/follow-up.php';` |
| `openapi/openapi.json` | Regenerate after implementation (manual/Scramble step). |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create 4 enum classes in `app/Modules/FollowUp/Enums/`. Reuse existing `TaskStatus`, `StageInstanceStatus`, `SubStageInstanceStatus`, `SlaTimerStatus` from Task/Tracking.

**Key decisions:**
- `FollowUpActionType` and `SlaHealth` are int-backed (stored as TINYINT).
- `BoardSortField` and `BoardSortDirection` are string-backed so the Form Request can use `Rule::enum()` and API consumers send semantic string values.
- All business logic compares enum cases, never raw integers.

**Files:**
- `app/Modules/FollowUp/Enums/FollowUpActionType.php`
- `app/Modules/FollowUp/Enums/SlaHealth.php`
- `app/Modules/FollowUp/Enums/BoardSortField.php`
- `app/Modules/FollowUp/Enums/BoardSortDirection.php`

**Code snippet — `FollowUpActionType`:**
```php
<?php

namespace App\Modules\FollowUp\Enums;

enum FollowUpActionType: int
{
    case PhoneCall = 1;
    case Message = 2;
    case Meeting = 3;
    case Email = 4;
    case Other = 5;
}
```

**Code snippet — `SlaHealth`:**
```php
<?php

namespace App\Modules\FollowUp\Enums;

enum SlaHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
    case Grey = 4;
}
```

**Code snippet — `BoardSortField`:**
```php
<?php

namespace App\Modules\FollowUp\Enums;

enum BoardSortField: int
{
    case Priority = 1;
    case DueDate = 2;
    case CreatedAt = 3;
    case TimeAtStage = 4;
    case Department = 5;
    case StageType = 6;
}
```

**Code snippet — `BoardSortDirection`:**
```php
<?php

namespace App\Modules\FollowUp\Enums;

enum BoardSortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
```

**Test cases:**
1. `FollowUpActionType::PhoneCall->value` → `1`
2. `SlaHealth::Red` is `instanceof SlaHealth` → `true`

**Rules:** `coding-standards.md` — Enum Usage. Use `Rule::enum()` in Form Requests; no magic numbers.

---

### 2. Migration — `follow_up_actions`

**One-line summary:** Single tenant migration creating the append-only follow-up action log table.

**Key decisions:**
- Append-only table: `public_id`, FKs to `tasks` and `users`, `action_type` TINYINT, `note_ar` required, `note_en` optional, `contact_name` optional, timestamps.
- Soft deletes are **not** added — history is immutable in MVP.
- Index on `(task_id, created_at)` for fast chronological listing.

**File:** `database/migrations/tenant/2026_06_14_000001_create_follow_up_actions_table.php`

**Code snippet:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedTinyInteger('action_type');
            $table->text('note_ar');
            $table->text('note_en')->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_actions');
    }
};
```

**Rules:** `coding-standards.md` — Migrations. No `tenant_id`; use `constrained()`; add indexes for query patterns.

---

### 3. Model — `FollowUpAction`

**One-line summary:** Tenant model with `public_id` route binding, casts `action_type` to enum, relationships to `Task` and `User`.

**File:** `app/Modules/FollowUp/Models/FollowUpAction.php`

**Code snippet:**
```php
<?php

namespace App\Modules\FollowUp\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\FollowUp\Enums\FollowUpActionType;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'user_id', 'action_type', 'note_ar', 'note_en', 'contact_name'])]
class FollowUpAction extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'action_type' => FollowUpActionType::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

**Test cases:**
1. `FollowUpAction::create([... 'action_type' => FollowUpActionType::PhoneCall])` → `action_type` casted correctly.
2. Route model binding resolves by `public_id` (inherited from `HasPublicId` trait).

**Rules:** `coding-standards.md` — Models. `#[Fillable]`, `casts()` method, no `tenant_id`.

---

### 4. Shared Concern — `EnrichesBoardTasks`

**One-line summary:** Trait that computes the current step, active assignees, SLA health, and working-seconds elapsed for each task on the current page.

**Key decisions:**
- Runs after cursor pagination so heavy calculations happen on ≤100 rows.
- Uses the tenant default working calendar (`WorkingCalendar::where('is_default', true)->firstOrFail()`); this mirrors SLA timer behaviour until `departments.working_calendar_id` is available.
- Sets runtime attributes (`_current_step`, `_current_assignees`, `_sla_health`, `_time_at_stage_seconds`) used by `BoardTaskResource`.

**File:** `app/Modules/FollowUp/Services/Concerns/EnrichesBoardTasks.php`

**Code snippet:**
```php
<?php

namespace App\Modules\FollowUp\Services\Concerns;

use App\Modules\FollowUp\Enums\SlaHealth;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\WorkingDayCalculator;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait EnrichesBoardTasks
{
    private function enrichTasks($tasks, WorkingDayCalculator $calculator): void
    {
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

            if ($step instanceof \App\Modules\Task\Models\TaskSubStageInstance) {
                $subStageIds->push($step->id);
            } elseif ($step instanceof \App\Modules\Task\Models\TaskStageInstance) {
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

            $timer = $step instanceof \App\Modules\Task\Models\TaskSubStageInstance
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

    private function currentStep($task): ?\App\Modules\Task\Models\TaskStageInstance|\App\Modules\Task\Models\TaskSubStageInstance
    {
        $activeStage = $task->stageInstances->first();

        if (! $activeStage) {
            return null;
        }

        return $activeStage->subStageInstances->first() ?? $activeStage;
    }
}
```

**Rules:** `coding-standards.md` — Caching (calendar could be cached cold tier in V2; MVP direct query). No caching of board pages.

---

### 5. Service — `FollowUpBoardService`

**One-line summary:** Builds the ABAC-filtered board query, applies filters/sort, runs pagination, and enriches results.

**Key decisions:**
- Reuses `App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility` for the base query.
- `board()`, `overdue()`, and `atRisk()` share the same query builder and enrichment pipeline.
- `bottlenecks()` is a separate bounded aggregation with a 300s warm cache.

**File:** `app/Modules/FollowUp/Services/FollowUpBoardService.php`

**Core `board()` signature & snippet:**
```php
public function board(User $user, array $filters): CursorPaginator
{
    try {
        $query = $this->buildBaseQuery($user, $filters);

        $this->applyStatusFilter($query, $filters['status'] ?? null);
        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $paginator = $query->cursorPaginate($filters['per_page'] ?? 15);
        $this->enrichTasks($paginator->items(), app(WorkingDayCalculator::class));

        return $paginator;
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
```

**`buildBaseQuery()` snippet:**
```php
private function buildBaseQuery(User $user, array $filters)
{
    return $this->baseTaskQuery($user)
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
        ->leftJoin('blueprint_stages', 'task_stage_instances.blueprint_stage_id', '=', 'blueprint_stages.id')
        ->leftJoin('stage_types', 'blueprint_stages.stage_type_id', '=', 'stage_types.id')
        ->leftJoin('departments', 'task_stage_instances.owning_department_id', '=', 'departments.id')
        ->leftJoin('task_priorities', 'tasks.priority_id', '=', 'task_priorities.id')
        ->select('tasks.*');
}
```

**`applySorting()` snippet:**
```php
private function applySorting($query, array $filters): void
{
    $field = BoardSortField::tryFrom($filters['sort_by'] ?? BoardSortField::TimeAtStage->value) ?? BoardSortField::TimeAtStage;
    $direction = BoardSortDirection::tryFrom($filters['sort_direction'] ?? BoardSortDirection::Desc->value)?->value ?? 'desc';

    match ($field) {
        BoardSortField::TimeAtStage => $query->orderBy('task_stage_instances.entered_at', $direction),
        BoardSortField::Priority => $query->orderBy('task_priorities.severity_rank', $direction),
        BoardSortField::DueDate => $query->orderBy('tasks.due_date', $direction),
        BoardSortField::CreatedAt => $query->orderBy('tasks.created_at', $direction),
        BoardSortField::Department => $query->orderBy('departments.name_ar', $direction),
        BoardSortField::StageType => $query->orderBy('stage_types.name_ar', $direction),
    };

    $query->orderBy('tasks.id');
}
```

**`applyStatusFilter()` snippet:**
```php
private function applyStatusFilter($query, ?string $status): void
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
}

private function applySlaStatusFilter($query, SlaTimerStatus $status): void
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
```

**`bottlenecks()` snippet:**
```php
public function bottlenecks(User $user, array $filters): array
{
    $this->ensureOrganizationOrFollowUpScope($user);

    $cacheKey = sprintf('%s:followup:bottlenecks', tenant()->slug);

    return Cache::remember($cacheKey, 300, function () use ($user, $filters) {
        try {
            $visibleTaskIds = $this->baseTaskQuery($user)
                ->where('tasks.status', TaskStatus::Active)
                ->pluck('id');

            if ($visibleTaskIds->isEmpty()) {
                return [];
            }

            $driver = DB::connection()->getDriverName();
            $avgExpr = $driver === 'sqlite'
                ? "AVG((strftime('%s', 'now') - strftime('%s', task_stage_instances.entered_at)))"
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
                    DB::raw($avgExpr.' as avg_time_at_stage_seconds'),
                ])
                ->groupBy('stage_types.id', 'departments.id')
                ->havingRaw('score > 0')
                ->orderByDesc('score')
                ->orderByDesc('avg_time_at_stage_seconds')
                ->limit($filters['limit'] ?? 10)
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
```

**Test cases:**
1. Board for a user with `task.view.follow_up_scope` and a monitoring-scope grant returns only tasks in scoped departments.
2. Board with `status=overdue` returns only tasks whose active SLA timer is `Breached`.

**Rules:** `coding-standards.md` — Cursor pagination on all list endpoints; tenant-prefixed cache keys for bottleneck; try/catch + module `followup` channel; no magic numbers (enums).

---

### 6. Service — `FollowUpActionService`

**One-line summary:** Validates ABAC visibility and capability, then creates or lists follow-up action log entries.

**Key decisions:**
- `create()` is a single write — no transaction required.
- Action creation requires the caller to have `task.view.follow_up_scope`, `task.view.organization`, or `task.view.department_touched`.
- Both `create()` and `list()` verify the parent task is visible to the caller via `TaskVisibilityScope`.

**File:** `app/Modules/FollowUp/Services/FollowUpActionService.php`

**Code snippet — `create()`:**
```php
public function create(Task $task, User $user, array $data): FollowUpAction
{
    try {
        $this->ensureCanLogActions($user);
        $this->ensureTaskVisible($task, $user);

        $action = FollowUpAction::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'action_type' => $data['action_type'],
            'note_ar' => $data['note_ar'],
            'note_en' => ! empty($data['note_en']) ? $data['note_en'] : $data['note_ar'],
            'contact_name' => $data['contact_name'] ?? null,
        ]);

        event(new FollowUpActionCreated($action));

        return $action->fresh(['user']);
    } catch (FollowUpActionNotAllowedException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('followup')->error('Failed to create follow-up action', [
            'tenant_slug' => tenant()->slug,
            'action' => 'followup.action.create',
            'entity_type' => 'follow_up_action',
            'entity_id' => null,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Code snippet — visibility helpers:**
```php
private function ensureCanLogActions(User $user): void
{
    if (
        ! $this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope')
        && ! $this->iamPolicy->hasCapability($user, 'task.view.organization')
        && ! $this->iamPolicy->hasCapability($user, 'task.view.department_touched')
    ) {
        throw new FollowUpActionNotAllowedException;
    }
}

private function ensureTaskVisible(Task $task, User $user): void
{
    $this->taskVisibilityScope->apply(
        Task::query()->where('tasks.id', $task->id),
        $user
    )->firstOrFail();
}
```

**Code snippet — `list()`:**
```php
public function list(Task $task, User $user, array $filters)
{
    try {
        $this->ensureTaskVisible($task, $user);

        return FollowUpAction::where('task_id', $task->id)
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($filters['per_page'] ?? 15);
    } catch (\Throwable $e) {
        Log::channel('followup')->error('Failed to list follow-up actions', [
            'tenant_slug' => tenant()->slug,
            'action' => 'followup.action.list',
            'entity_type' => 'follow_up_action',
            'entity_id' => null,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Test cases:**
1. User with `task.view.follow_up_scope` logs an action on a visible task → action created, `FollowUpActionCreated` event dispatched.
2. User without any required capability attempts to log an action → `403`.

**Rules:** `coding-standards.md` — Error Handling (try/catch + `followup` channel), Events (`ShouldDispatchAfterCommit`), ABAC enforcement.

---

### 7. Events

**One-line summary:** `FollowUpActionCreated` implements `ShouldDispatchAfterCommit`.

**File:** `app/Modules/FollowUp/Events/FollowUpActionCreated.php`

**Code snippet:**
```php
<?php

namespace App\Modules\FollowUp\Events;

use App\Modules\FollowUp\Models\FollowUpAction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class FollowUpActionCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public FollowUpAction $action) {}
}
```

**Rules:** `coding-standards.md` — Domain Events must implement `ShouldDispatchAfterCommit`.

---

### 8. Exceptions

**One-line summary:** Two domain exceptions extending `App\Exceptions\DomainException`.

**Files:**
- `app/Modules/FollowUp/Exceptions/FollowUpActionNotAllowedException.php` (status 403)
- `app/Modules/FollowUp/Exceptions/InvalidBoardFilterException.php` (status 422)

**Code snippet:**
```php
<?php

namespace App\Modules\FollowUp\Exceptions;

use App\Exceptions\DomainException;

class FollowUpActionNotAllowedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct('You do not have permission to log follow-up actions.');
    }
}
```

**Rules:** `coding-standards.md` — Error Handling. Domain exceptions extend `DomainException`; registered automatically by existing handler in `bootstrap/app.php`.

---

### 9. Form Requests

**One-line summary:** Three Form Requests using `Rule::enum()` for enum fields.

**Files:**
- `app/Modules/FollowUp/Requests/BoardRequest.php`
- `app/Modules/FollowUp/Requests/StoreFollowUpActionRequest.php`
- `app/Modules/FollowUp/Requests/ListFollowUpActionsRequest.php`

**Code snippet — `BoardRequest`:**
```php
<?php

namespace App\Modules\FollowUp\Requests;

use App\Modules\FollowUp\Enums\BoardSortDirection;
use App\Modules\FollowUp\Enums\BoardSortField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,suspended,overdue,at_risk,completed,cancelled'],
            'stage_type_id' => ['nullable', 'string', 'uuid'],
            'assignee_id' => ['nullable', 'string', 'uuid'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'priority_id' => ['nullable', 'array'],
            'priority_id.*' => ['string', 'uuid'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_field' => ['nullable', 'string', 'in:created_at,due_date,completed_at'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', Rule::enum(BoardSortField::class)],
            'sort_direction' => ['nullable', Rule::enum(BoardSortDirection::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

**Code snippet — `StoreFollowUpActionRequest`:**
```php
<?php

namespace App\Modules\FollowUp\Requests;

use App\Modules\FollowUp\Enums\FollowUpActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::enum(FollowUpActionType::class)],
            'note_ar' => ['required', 'string', 'max:5000'],
            'note_en' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation in Form Requests; `Rule::enum()` for enums.

---

### 10. Controllers

**One-line summary:** Thin controllers that validate requests, check rate limits, call services, and return API Resources.

**Files:**
- `app/Modules/FollowUp/Controllers/FollowUpBoardController.php`
- `app/Modules/FollowUp/Controllers/FollowUpActionController.php`

**Code snippet — `FollowUpBoardController`:**
```php
<?php

namespace App\Modules\FollowUp\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FollowUp\Requests\BoardRequest;
use App\Modules\FollowUp\Resources\BoardTaskResource;
use App\Modules\FollowUp\Resources\BottleneckResource;
use App\Modules\FollowUp\Services\FollowUpBoardService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class FollowUpBoardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private FollowUpBoardService $boardService,
    ) {}

    public function board(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->boardService->board($request->user(), $request->validated());
        $paginator->through(fn ($task) => new BoardTaskResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function overdue(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['status' => 'overdue']);
        $paginator = $this->boardService->board($request->user(), $filters);
        $paginator->through(fn ($task) => new BoardTaskResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function atRisk(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['status' => 'at_risk']);
        $paginator = $this->boardService->board($request->user(), $filters);
        $paginator->through(fn ($task) => new BoardTaskResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function bottlenecks(BoardRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->boardService->bottlenecks($request->user(), $request->validated());

        return BottleneckResource::collection($items);
    }
}
```

**Code snippet — `FollowUpActionController`:**
```php
<?php

namespace App\Modules\FollowUp\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FollowUp\Requests\ListFollowUpActionsRequest;
use App\Modules\FollowUp\Requests\StoreFollowUpActionRequest;
use App\Modules\FollowUp\Resources\FollowUpActionResource;
use App\Modules\FollowUp\Services\FollowUpActionService;
use App\Modules\Task\Models\Task;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class FollowUpActionController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private FollowUpActionService $actionService,
    ) {}

    public function store(StoreFollowUpActionRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $action = $this->actionService->create($task, $request->user(), $request->validated());

        return response()->json(new FollowUpActionResource($action), 201);
    }

    public function index(ListFollowUpActionsRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->actionService->list($task, $request->user(), $request->validated());
        $paginator->through(fn ($action) => new FollowUpActionResource($action));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**Rules:** `coding-standards.md` — Controllers are thin; rate limiting via `HasRateLimiting` trait (`LIST` for reads, `MUTATE` for action creation).

---

### 11. API Resources

**One-line summary:** Transform board rows, bottleneck rows, and follow-up action rows to JSON. Expose only `public_id`.

**Files:**
- `app/Modules/FollowUp/Resources/BoardTaskResource.php`
- `app/Modules/FollowUp/Resources/BottleneckResource.php`
- `app/Modules/FollowUp/Resources/FollowUpActionResource.php`

**Code snippet — `BoardTaskResource`:**
```php
<?php

namespace App\Modules\FollowUp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $step = $task->_current_step;
        $stageInstance = $step instanceof \App\Modules\Task\Models\TaskSubStageInstance
            ? $step->parentStageInstance
            : $step;
        $subStageInstance = $step instanceof \App\Modules\Task\Models\TaskSubStageInstance ? $step : null;

        return [
            'public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en,
            'status' => $task->status,
            'priority' => $task->priority ? [
                'public_id' => $task->priority->public_id,
                'name_ar' => $task->priority->name_ar,
                'name_en' => $task->priority->name_en,
            ] : null,
            'classification_level' => $task->classification_level,
            'current_stage' => [
                'public_id' => $subStageInstance?->blueprintSubStage?->public_id ?? $stageInstance?->blueprintStage?->public_id,
                'name_ar' => $subStageInstance?->blueprintSubStage?->name_ar ?? $stageInstance?->blueprintStage?->name_ar,
                'name_en' => $subStageInstance?->blueprintSubStage?->name_en ?? $stageInstance?->blueprintStage?->name_en,
                'stage_type' => $stageInstance?->blueprintStage?->stageType ? [
                    'public_id' => $stageInstance->blueprintStage->stageType->public_id,
                    'name_ar' => $stageInstance->blueprintStage->stageType->name_ar,
                    'name_en' => $stageInstance->blueprintStage->stageType->name_en,
                ] : null,
            ],
            'current_assignees' => $task->_current_assignees->map(fn ($a) => [
                'public_id' => $a->user?->public_id,
                'name_ar' => $a->user?->name_ar,
                'name_en' => $a->user?->name_en,
                'position_public_id' => $a->position?->public_id,
            ])->filter(fn ($u) => $u['public_id'] !== null)->values(),
            'sla_health' => $task->_sla_health->name ?? 'green',
            'time_at_current_stage_seconds' => $task->_time_at_stage_seconds ?? 0,
            'department' => $step?->owningDepartment ? [
                'public_id' => $step->owningDepartment->public_id,
                'name_ar' => $step->owningDepartment->name_ar,
                'name_en' => $step->owningDepartment->name_en,
            ] : null,
            'blueprint_category' => $task->blueprint?->category ? [
                'public_id' => $task->blueprint->category->public_id,
                'name_ar' => $task->blueprint->category->name_ar,
                'name_en' => $task->blueprint->category->name_en,
            ] : null,
            'due_date' => $task->due_date?->toDateString(),
            'created_at' => $task->created_at?->toIso8601String(),
            'launched_at' => $task->launched_at?->toIso8601String(),
        ];
    }
}
```

**Code snippet — `FollowUpActionResource`:**
```php
<?php

namespace App\Modules\FollowUp\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'action_type' => $this->action_type->name,
            'note_ar' => $this->note_ar,
            'note_en' => $this->note_en ?? $this->note_ar,
            'contact_name' => $this->contact_name,
            'created_by' => $this->user ? [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources required; expose only `public_id`; eager-load relationships to avoid N+1.

---

### 12. Cache Invalidation Listeners for Bottlenecks

**One-line summary:** Forget the bottleneck cache key when Task/Tracking lifecycle events change overdue/at-risk counts.

**Files:**
- `app/Modules/FollowUp/Listeners/Concerns/InvalidatesFollowUpBottleneckCache.php`
- One listener per event: `InvalidateBottleneckOnStageCompleted`, `InvalidateBottleneckOnStageAdvanced`, `InvalidateBottleneckOnStageReturned`, `InvalidateBottleneckOnSubStageCompleted`, `InvalidateBottleneckOnSlaWarning`, `InvalidateBottleneckOnSlaBreach`.

**Code snippet — shared concern:**
```php
<?php

namespace App\Modules\FollowUp\Listeners\Concerns;

use Illuminate\Support\Facades\Cache;

trait InvalidatesFollowUpBottleneckCache
{
    protected function invalidateBottleneckCache(): void
    {
        Cache::forget(sprintf('%s:followup:bottlenecks', tenant()->slug));
    }
}
```

**Code snippet — listener example:**
```php
<?php

namespace App\Modules\FollowUp\Listeners;

use App\Modules\FollowUp\Listeners\Concerns\InvalidatesFollowUpBottleneckCache;
use App\Modules\Task\Events\StageInstanceCompleted;

class InvalidateBottleneckOnStageCompleted
{
    use InvalidatesFollowUpBottleneckCache;

    public function handle(StageInstanceCompleted $event): void
    {
        $this->invalidateBottleneckCache();
    }
}
```

**Rules:** `coding-standards.md` — Cache invalidation by domain events; tenant-prefixed keys.

---

### 13. Routes

**One-line summary:** Register follow-up routes under `/api/v1/follow-up`.

**File:** `routes/api/v1/follow-up.php`

**Code snippet:**
```php
<?php

use App\Modules\FollowUp\Controllers\FollowUpActionController;
use App\Modules\FollowUp\Controllers\FollowUpBoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('follow-up')->group(function () {
    Route::get('board', [FollowUpBoardController::class, 'board']);
    Route::get('overdue', [FollowUpBoardController::class, 'overdue']);
    Route::get('at-risk', [FollowUpBoardController::class, 'atRisk']);
    Route::get('bottlenecks', [FollowUpBoardController::class, 'bottlenecks']);

    Route::prefix('tasks/{task}')->group(function () {
        Route::get('actions', [FollowUpActionController::class, 'index']);
        Route::post('actions', [FollowUpActionController::class, 'store']);
    });
});
```

**File:** `routes/tenant.php`

**Change:**
```php
require __DIR__.'/api/v1/follow-up.php';
```

**Rules:** `coding-standards.md` — Versioned, kebab-case routes.

---

### 14. Logging Channel

**One-line summary:** Add `followup` daily channel to `config/logging.php`.

**Code snippet:**
```php
'followup' => [
    'driver' => 'daily',
    'path' => storage_path('logs/followup/followup.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
    'replace_placeholders' => true,
],
```

**Rules:** `coding-standards.md` — Per-module logging channel; structured context in service logs.

---

### 15. Feature Tests

**One-line summary:** Two Pest feature test files using Pattern A (tenant provision + seed + `actingAs`).

**Files:**
- `tests/Feature/Modules/FollowUp/FollowUpBoardTest.php`
- `tests/Feature/Modules/FollowUp/FollowUpActionTest.php`

**Key test cases (board):**
- Follow-up specialist with monitoring scope sees only scoped tasks.
- Org-wide viewer (`task.view.organization`) sees all non-confidential tasks.
- Draft tasks are excluded.
- Confidential tasks are hidden without `task.confidential.view_metadata`.
- Each filter works in isolation (status, stage_type, assignee, department, priority, category, date range, search).
- Sort by `time_at_stage` and `priority`.
- Overdue and at-risk lists return only matching SLA timer statuses.
- Bottleneck endpoint returns bounded list (max 10) and requires `task.view.organization` or `task.view.follow_up_scope`.
- Cursor pagination contract (`data`, `next_cursor`, `has_more`).

**Key test cases (actions):**
- Create action on visible task with required capability → 201.
- List actions chronological order.
- Missing capability → 403.
- Invisible task → 404.
- Validation failures (`action_type`, `note_ar`) → 422.

**Rules:** `testing-policy.md` — Feature tests mandatory; use factories; `RefreshDatabase`; assert cursor pagination shape.

---

## Execution Order

| Step | Task | Depends On |
|------|------|------------|
| 1 | Add `followup` channel to `config/logging.php` | — |
| 2 | Create enums (`FollowUpActionType`, `SlaHealth`, `BoardSortField`, `BoardSortDirection`) | — |
| 3 | Create `follow_up_actions` migration | — |
| 4 | Create `FollowUpAction` model + factory | Step 3 |
| 5 | Create exceptions (`FollowUpActionNotAllowedException`, `InvalidBoardFilterException`) | — |
| 6 | Create `FollowUpActionCreated` event | Step 4 |
| 7 | Create Form Requests (`BoardRequest`, `StoreFollowUpActionRequest`, `ListFollowUpActionsRequest`) | Step 2 |
| 8 | Create `EnrichesBoardTasks` concern | — |
| 9 | Create `FollowUpBoardService` | Steps 4, 7, 8 |
| 10 | Create `FollowUpActionService` | Steps 4, 6 |
| 11 | Create API Resources (`BoardTaskResource`, `BottleneckResource`, `FollowUpActionResource`) | Step 8 |
| 12 | Create controllers (`FollowUpBoardController`, `FollowUpActionController`) | Steps 9, 10, 11 |
| 13 | Create cache-invalidation listeners + concern | — |
| 14 | Create `routes/api/v1/follow-up.php` and require it in `routes/tenant.php` | Step 12 |
| 15 | Create feature tests | Steps 1–14 |
| 16 | Run `php artisan test --compact` and fix failures | Step 15 |
| 17 | Run `vendor/bin/pint --dirty --format agent` | Step 16 |
| 18 | Regenerate `openapi/openapi.json` | Step 14 |

---

## API Contract Summary

| Method | Endpoint | Auth | Rate Limit | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/follow-up/board` | Sanctum | `LIST` | Cursor-paginated, filterable board (ABAC via `TaskVisibilityScope`). |
| GET | `/api/v1/follow-up/overdue` | Sanctum | `LIST` | Tasks with active SLA timer `Breached`, sorted by active stage age. |
| GET | `/api/v1/follow-up/at-risk` | Sanctum | `LIST` | Tasks with active SLA timer `Warning`, sorted by active stage age. |
| GET | `/api/v1/follow-up/bottlenecks` | Sanctum + `task.view.organization`/`task.view.follow_up_scope` | `LIST` | Top 10 stage type + department combinations by overdue/at-risk score. |
| POST | `/api/v1/follow-up/tasks/{task}/actions` | Sanctum + `task.view.follow_up_scope`/`organization`/`department_touched` | `MUTATE` | Append a follow-up action to a task. |
| GET | `/api/v1/follow-up/tasks/{task}/actions` | Sanctum + task visibility | `LIST` | Cursor-paginated chronological action log for a task. |

**Pagination contract:**
- Board, overdue, at-risk, and action list: `{data: [...], next_cursor: "...", has_more: bool}`.
- Bottlenecks: `{data: [...]}` (bounded, no pagination).

---

## What to Test Manually

1. **Happy path board load** — Log in as follow-up specialist with monitoring scope → `/follow-up/board` returns only tasks in scoped departments, no drafts, no archived tasks.
2. **Org-wide vs scoped visibility** — Same board viewed by a user with `task.view.organization` returns all non-confidential launched tasks; scoped user sees subset.
3. **Confidential filtering** — Confidential task created by another user is hidden unless viewer is participant or has `task.confidential.view_metadata`.
4. **Status filters** — Toggle `status=active`, `suspended`, `completed`, `cancelled`, `overdue`, `at_risk`; verify each returns the expected subset.
5. **Stage type filter** — Filter by `stage_type_id`; verify only tasks whose active parent stage has that type are returned.
6. **Assignee filter** — Filter by `assignee_id`; verify tasks where that user has an active assignment on the current step are returned.
7. **Department filter** — Filter by `department_id`; verify tasks whose current step is owned by that department are returned.
8. **Priority filter** — Pass multiple `priority_id` values; verify only matching priorities.
9. **Date range filter** — Use `date_from`/`date_to` with each `date_field` (`created_at`, `due_date`, `completed_at`).
10. **Search filter** — Search by partial `title_ar` and `title_en`.
11. **External reference filter (when Spec 014 ready)** — Filter by `external_reference` and verify match; before 014 verify 422.
12. **Sorting** — Sort by `time_at_stage`, `priority`, `due_date`, `created_at`, `department`, `stage_type` in both directions; verify order.
13. **Time-at-stage accuracy** — Compare `time_at_current_stage_seconds` against `WorkingDayCalculator` output for a known calendar.
14. **SLA health indicators** — Verify Green (running/no timer), Amber (warning), Red (breached), Grey (suspended) per task.
15. **Overdue list** — Verify only `Breached` tasks, sorted with most overdue first.
16. **At-risk list** — Verify only `Warning` tasks.
17. **Bottleneck cache** — Hit `/follow-up/bottlenecks` twice; second response should be cached. Trigger a stage completion and verify cache invalidates (next request recomputes).
18. **Action creation** — Log an action with each `action_type`; verify `note_en` falls back to `note_ar`.
19. **Action append-only** — Confirm no PUT/PATCH/DELETE endpoints exist.
20. **Action capability denial** — User without required capability gets 403 when posting action.
21. **Action list pagination** — Create 20+ actions; verify cursor pagination contract.
22. **Rate limiting** — Hit list endpoint 61 times/minute → 429; hit action create 31 times/minute → 429.
23. **Event dispatch** — Create action and verify `FollowUpActionCreated` listener receives event after DB commit.
24. **Tenant isolation** — Ensure data from tenant A never appears in tenant B board.
25. **Calendar edge case** — Time-at-stage excludes weekends/holidays defined in the tenant default working calendar.
