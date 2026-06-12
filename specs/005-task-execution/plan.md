# Plan: Task Execution

> **Spec:** 005-task-execution
> **Date:** 2026-06-11
> **Status:** approved

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| New capability `task.manage_priorities`? | **Yes — new capability.** | Priorities are a Task module concern, not Blueprint. Add `task.manage_priorities` to `CapabilitySeeder`. |
| New capability `task.create`? | **No dedicated capability.** | Any authenticated `internal_user` or `tenant_admin` can create tasks — it's a universal action. Only `classification_level = confidential` requires `task.classify.confidential`. |
| New capability `task.manage`? | **Yes — new capability.** | Allows tenant admins to update/delete other users' drafts. Initiator always manages their own drafts. Add `task.manage` to `CapabilitySeeder`. |
| Suspension reason storage? | **Add `suspension_reason` column.** | Direct query access needed, mirrors `cancellation_reason` pattern on the same table. |
| Task list ABAC filtering? | **Inline query filter.** | Post-query filtering wastes DB reads on large tables. Build ABAC WHERE clauses into the query. |
| Default priority enforcement? | **`priority_id` optional; system uses default.** | If `priority_id` is omitted in the request, the service layer selects the `is_default = true` priority automatically. |

---

## Technical Approach

Build the Task module under `app/Modules/Task/` following the established Blueprint module pattern — 5 tables, 5 enums, 3 services, 3 controllers, API Resources for all responses. The core complexity is the `launch()` transaction which atomically transitions draft → active, locks the Blueprint, creates Stage 1 instances, and resolves assignees via a dedicated `AssignmentResolutionService`. All mutations protected by `RequireCapability` middleware or inline initiator checks. ABAC visibility filtering built into task list query via `TaskVisibilityScope`.

**Key decisions:**
- **Assignment resolution as a dedicated service** — isolates the complex position → user → delegation lookup logic, making it testable independently and reusable by Spec 006
- **State machine validation as enum method** — `TaskStatus::canTransitionTo()` keeps transition logic co-located with the enum
- **ABAC visibility as an Eloquent scope** — `TaskVisibilityScope` builds the WHERE clause based on user capabilities, avoiding post-query filtering
- **No queue jobs** — all operations (including launch) are synchronous; assignment resolution queries 1-5 indexed rows

---

## Affected Modules / Files

### New Files (to create)

| File | Purpose |
|------|---------|
| **Enums** | |
| `app/Modules/Task/Enums/TaskStatus.php` | Draft(1), Active(2), Suspended(3), Completed(4), Cancelled(5) |
| `app/Modules/Task/Enums/ClassificationLevel.php` | Public(1), Internal(2), Confidential(3) |
| `app/Modules/Task/Enums/StageInstanceStatus.php` | Pending(1), Active(2), Completed(3), Returned(4), Skipped(5) |
| `app/Modules/Task/Enums/SubStageInstanceStatus.php` | Pending(1), Active(2), Completed(3), Returned(4) |
| `app/Modules/Task/Enums/AssignmentRole.php` | Required(1), Optional(2), Lead(3) |
| **Migrations** | |
| `database/migrations/tenant/2026_06_11_000001_create_task_priorities_table.php` | task_priorities table |
| `database/migrations/tenant/2026_06_11_000002_create_tasks_table.php` | tasks table |
| `database/migrations/tenant/2026_06_11_000003_create_task_stage_instances_table.php` | task_stage_instances table |
| `database/migrations/tenant/2026_06_11_000004_create_task_sub_stage_instances_table.php` | task_sub_stage_instances table |
| `database/migrations/tenant/2026_06_11_000005_create_task_stage_assignments_table.php` | task_stage_assignments table |
| **Models** | |
| `app/Modules/Task/Models/TaskPriority.php` | Priority entity |
| `app/Modules/Task/Models/Task.php` | Task entity |
| `app/Modules/Task/Models/TaskStageInstance.php` | Stage instance entity |
| `app/Modules/Task/Models/TaskSubStageInstance.php` | Sub-stage instance entity |
| `app/Modules/Task/Models/TaskStageAssignment.php` | Assignment entity |
| **Services** | |
| `app/Modules/Task/Services/TaskPriorityService.php` | Priority CRUD + default swap + caching |
| `app/Modules/Task/Services/TaskService.php` | Task CRUD + launch + lifecycle (suspend, resume, cancel) |
| `app/Modules/Task/Services/AssignmentResolutionService.php` | Position → user → delegation resolution |
| **Controllers** | |
| `app/Modules/Task/Controllers/TaskPriorityController.php` | Priority API |
| `app/Modules/Task/Controllers/TaskController.php` | Task CRUD + launch + lifecycle API |
| **Requests** | |
| `app/Modules/Task/Requests/StoreTaskPriorityRequest.php` | Priority creation validation |
| `app/Modules/Task/Requests/UpdateTaskPriorityRequest.php` | Priority update validation |
| `app/Modules/Task/Requests/StoreTaskRequest.php` | Task creation validation |
| `app/Modules/Task/Requests/UpdateTaskRequest.php` | Task draft update validation |
| `app/Modules/Task/Requests/LaunchTaskRequest.php` | Launch validation (manual_assignments) |
| `app/Modules/Task/Requests/SuspendTaskRequest.php` | Suspend validation (reason required) |
| `app/Modules/Task/Requests/CancelTaskRequest.php` | Cancel validation (reason required) |
| **Resources** | |
| `app/Modules/Task/Resources/TaskPriorityResource.php` | Priority JSON shape |
| `app/Modules/Task/Resources/TaskResource.php` | Task JSON shape |
| `app/Modules/Task/Resources/TaskDetailResource.php` | Task show with stage instances |
| `app/Modules/Task/Resources/TaskStageInstanceResource.php` | Stage instance JSON shape |
| `app/Modules/Task/Resources/TaskSubStageInstanceResource.php` | Sub-stage instance JSON shape |
| `app/Modules/Task/Resources/TaskStageAssignmentResource.php` | Assignment JSON shape |
| **Events** | |
| `app/Modules/Task/Events/TaskCreated.php` | Domain event |
| `app/Modules/Task/Events/TaskUpdated.php` | Domain event |
| `app/Modules/Task/Events/TaskLaunched.php` | Domain event |
| `app/Modules/Task/Events/TaskSuspended.php` | Domain event |
| `app/Modules/Task/Events/TaskResumed.php` | Domain event |
| `app/Modules/Task/Events/TaskCancelled.php` | Domain event |
| `app/Modules/Task/Events/StageInstanceCreated.php` | Domain event |
| `app/Modules/Task/Events/SubStageInstanceCreated.php` | Domain event |
| `app/Modules/Task/Events/StageAssignmentCreated.php` | Domain event |
| `app/Modules/Task/Events/TaskPriorityCreated.php` | Domain event |
| `app/Modules/Task/Events/TaskPriorityUpdated.php` | Domain event |
| **Exceptions** | |
| `app/Modules/Task/Exceptions/TaskNotDraftException.php` | Attempt to update/launch non-draft task |
| `app/Modules/Task/Exceptions/TaskNotActiveException.php` | Attempt to suspend non-active task |
| `app/Modules/Task/Exceptions/TaskNotSuspendedException.php` | Attempt to resume non-suspended task |
| `app/Modules/Task/Exceptions/BlueprintNotActiveException.php` | Blueprint is inactive |
| `app/Modules/Task/Exceptions/BlueprintHasNoStagesException.php` | Blueprint has no stages |
| `app/Modules/Task/Exceptions/UnresolvableAssignmentException.php` | Position is vacant |
| `app/Modules/Task/Exceptions/MissingManualAssignmentException.php` | Manual stage has no users |
| `app/Modules/Task/Exceptions/InvalidTaskStateTransitionException.php` | Invalid lifecycle transition |
| `app/Modules/Task/Exceptions/TaskAlreadyCancelledException.php` | Already cancelled |
| **Scopes** | |
| `app/Modules/Task/Scopes/TaskVisibilityScope.php` | ABAC-aware query scope for task listing |
| **Routes** | |
| `routes/api/v1/tasks.php` | Task + Priority routes |
| **Tests** | |
| `tests/Feature/Api/V1/Task/TaskPriorityTest.php` | Priority CRUD tests |
| `tests/Feature/Api/V1/Task/TaskTest.php` | Task CRUD + launch tests |
| `tests/Feature/Api/V1/Task/TaskLifecycleTest.php` | Suspend/resume/cancel tests |
| `tests/Feature/Api/V1/Task/AssignmentResolutionTest.php` | Assignment resolution unit/feature tests |
| **Factories** | |
| `database/factories/TaskPriorityFactory.php` | Priority factory |
| `database/factories/TaskFactory.php` | Task factory |
| `database/factories/TaskStageInstanceFactory.php` | Stage instance factory |
| `database/factories/TaskStageAssignmentFactory.php` | Assignment factory |

### Modified Files (to edit)

| File | Change |
|------|--------|
| `config/logging.php` | Add `task` logging channel |
| `bootstrap/app.php` | Register 9 Task exceptions |
| `database/seeders/TenantDatabaseSeeder.php` | Seed 3 default task priorities |
| `database/seeders/CapabilitySeeder.php` | Add `task.manage_priorities` and `task.manage` capabilities |
| `routes/api.php` | Include `tasks.php` route file (if required by routing setup) |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create 5 enum classes in `app/Modules/Task/Enums/`. Reuse `AssignmentType`, `AssignmentCardinality`, `CompletionRule` from `app/Modules/Blueprint/Enums/`.

**Key decisions:**
- `TaskStatus` includes a `canTransitionTo()` method for state machine validation
- All enums store TINYINT values, cast in model `casts()` method
- Form requests use `Rule::enum(ClassName::class)`

**Files:**
- `app/Modules/Task/Enums/TaskStatus.php`
- `app/Modules/Task/Enums/ClassificationLevel.php`
- `app/Modules/Task/Enums/StageInstanceStatus.php`
- `app/Modules/Task/Enums/SubStageInstanceStatus.php`
- `app/Modules/Task/Enums/AssignmentRole.php`

**Code snippet — TaskStatus with state machine:**
```php
<?php

namespace App\Modules\Task\Enums;

enum TaskStatus: int
{
    case Draft = 1;
    case Active = 2;
    case Suspended = 3;
    case Completed = 4;
    case Cancelled = 5;

    /**
     * @return array<TaskStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Completed, self::Cancelled],
            self::Suspended => [self::Active, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

**Code snippet — ClassificationLevel:**
```php
<?php

namespace App\Modules\Task\Enums;

enum ClassificationLevel: int
{
    case Public = 1;
    case Internal = 2;
    case Confidential = 3;
}
```

**Test cases:**
1. `TaskStatus::Draft->canTransitionTo(TaskStatus::Active)` → `true`
2. `TaskStatus::Completed->canTransitionTo(TaskStatus::Active)` → `false`

**Rules:** `coding-standards.md` — Enum Usage. Use `Rule::enum()` in Form Requests. No magic numbers.

---

### 2. Migrations

**One-line summary:** Create 5 migrations in `database/migrations/tenant/`. All use `bigIncrements`, `public_id` (UUID v7), proper FKs, and indexes.

**Key decisions:**
- `task_priorities`: soft deletes, `is_default` with only-one-true constraint enforced in service
- `tasks`: soft deletes, `suspension_reason` column added (resolved open question), comprehensive timestamp columns for each lifecycle state
- `task_stage_instances`: no soft deletes, no `public_id` (internal tracking, never exposed as top-level API entity)
- `task_sub_stage_instances`: no soft deletes, no `public_id`
- `task_stage_assignments`: no soft deletes, no `public_id`, composite check on `stage_instance_id XOR sub_stage_instance_id` enforced at service layer
- Indexes on `tasks.status`, `tasks.blueprint_id`, `tasks.initiator_user_id`, `tasks.priority_id` for query performance
- Index on `task_stage_assignments.user_id` for "my tasks" queries

**Code snippet — `create_tasks_table`:**
```php
Schema::create('tasks', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('blueprint_id')->constrained('blueprints');
    $table->foreignId('priority_id')->constrained('task_priorities');
    $table->string('title_ar');
    $table->string('title_en')->nullable();
    $table->text('description_ar');
    $table->text('description_en')->nullable();
    $table->unsignedTinyInteger('classification_level')->default(1);
    $table->foreignId('initiator_user_id')->constrained('users');
    $table->unsignedTinyInteger('status')->default(1);
    $table->date('due_date')->nullable();
    $table->timestamps();
    $table->timestamp('launched_at')->nullable();
    $table->timestamp('suspended_at')->nullable();
    $table->text('suspension_reason')->nullable();
    $table->timestamp('resumed_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();
    $table->text('cancellation_reason')->nullable();
    $table->timestamp('archived_at')->nullable();
    $table->foreignId('archived_by_user_id')->nullable()->constrained('users');
    $table->softDeletes();

    $table->index('status');
    $table->index('blueprint_id');
    $table->index('initiator_user_id');
    $table->index('priority_id');
    $table->index(['status', 'classification_level']);
});
```

**Code snippet — `create_task_priorities_table`:**
```php
Schema::create('task_priorities', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->string('name_ar');
    $table->string('name_en')->nullable();
    $table->unsignedSmallInteger('severity_rank');
    $table->string('color_code', 20)->nullable();
    $table->boolean('is_default')->default(false);
    $table->boolean('is_active')->default(true);
    $table->unsignedSmallInteger('display_order')->default(0);
    $table->timestamps();
    $table->softDeletes();
});
```

**Code snippet — `create_task_stage_instances_table`:**
```php
Schema::create('task_stage_instances', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('blueprint_stage_id')->constrained('blueprint_stages');
    $table->unsignedSmallInteger('sequence_order');
    $table->foreignId('owning_department_id')->nullable()->constrained('departments')->nullOnDelete();
    $table->unsignedTinyInteger('completion_rule');
    $table->unsignedTinyInteger('status')->default(1);
    $table->timestamp('entered_at')->nullable();
    $table->timestamp('exited_at')->nullable();
    $table->text('completion_note')->nullable();
    $table->text('return_reason')->nullable();
    $table->timestamp('created_at')->useCurrent();

    $table->index(['task_id', 'status']);
});
```

**Code snippet — `create_task_sub_stage_instances_table`:**
```php
Schema::create('task_sub_stage_instances', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('parent_stage_instance_id')->constrained('task_stage_instances')->cascadeOnDelete();
    $table->foreignId('blueprint_sub_stage_id')->constrained('blueprint_sub_stages');
    $table->unsignedSmallInteger('sequence_order');
    $table->foreignId('owning_department_id')->nullable()->constrained('departments')->nullOnDelete();
    $table->boolean('is_required');
    $table->unsignedTinyInteger('completion_rule');
    $table->unsignedTinyInteger('status')->default(1);
    $table->timestamp('entered_at')->nullable();
    $table->timestamp('exited_at')->nullable();
    $table->text('completion_note')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

**Code snippet — `create_task_stage_assignments_table`:**
```php
Schema::create('task_stage_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
    $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');
    $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
    $table->foreignId('delegated_from_user_id')->nullable()->constrained('users');
    $table->unsignedTinyInteger('assignment_role')->default(1);
    $table->boolean('is_completed')->default(false);
    $table->timestamp('assigned_at');
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('reassigned_at')->nullable();
    $table->foreignId('reassigned_by_user_id')->nullable()->constrained('users');
    $table->text('reassignment_reason')->nullable();

    $table->index('user_id');
    $table->index(['task_id', 'stage_instance_id']);
});
```

**Rules:** `coding-standards.md` — Migrations. No `tenant_id`. Use `constrained()`. Proper indexes for query performance.

---

### 3. Models

**One-line summary:** Extend `TenantModel`, use `#[Fillable]`, `SoftDeletes` where applicable, define casts and relationships. Follow `Blueprint` model pattern exactly.

**Key decisions:**
- `TaskPriority`: `SoftDeletes`, `HasFactory`, `scopeActive()`
- `Task`: `SoftDeletes`, `HasFactory`, casts all enum columns, relationships to blueprint, priority, initiator, stageInstances, assignments
- `TaskStageInstance`: No soft deletes, no `HasPublicId`, relationships to task, blueprintStage, subStageInstances, assignments
- `TaskSubStageInstance`: No soft deletes, no `HasPublicId`
- `TaskStageAssignment`: No soft deletes, no `HasPublicId`, relationships to user, position, stageInstance

**Code snippet — Task model:**
```php
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
    'initiator_user_id', 'status', 'due_date',
    'launched_at', 'suspended_at', 'suspension_reason',
    'resumed_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
    'archived_at', 'archived_by_user_id',
])]
class Task extends TenantModel
{
    use HasFactory, SoftDeletes;

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
```

**Code snippet — TaskPriority model:**
```php
<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_ar', 'name_en', 'severity_rank', 'color_code', 'is_default', 'is_active', 'display_order'])]
class TaskPriority extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'severity_rank' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

**Test cases:**
1. `Task::create([... 'status' => TaskStatus::Draft]); $task->isDraft()` → `true`
2. `TaskPriority::active()->get()` → returns only `is_active = true` rows

**Rules:** `coding-standards.md` — Models. No `tenant_id`. Use `casts()` method. `#[Fillable]` attribute.

---

### 4. Services

#### 4a. TaskPriorityService

**One-line summary:** Priority CRUD with cached listing, default-swap transaction, and domain events.

**Key decisions:**
- `getAll()` uses `Cache::remember()` with warm TTL (300s), key `{tenant_slug}:task:priorities:all`
- `create()` / `update()` clear cache
- Setting `is_default = true` unsets old default inside `DB::transaction()`
- try/catch with `Log::channel('task')`

**Code snippet — default swap:**
```php
public function create(array $data): TaskPriority
{
    try {
        return DB::transaction(function () use ($data) {
            if (!empty($data['is_default']) && $data['is_default']) {
                TaskPriority::where('is_default', true)->update(['is_default' => false]);
            }

            $priority = TaskPriority::create([
                'name_ar' => $data['name_ar'],
                'name_en' => !empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'severity_rank' => $data['severity_rank'],
                'color_code' => $data['color_code'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => true,
                'display_order' => $data['display_order'] ?? 0,
            ]);

            $this->clearCache();
            event(new TaskPriorityCreated($priority));

            return $priority;
        });
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to create task priority', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'task_priority.create',
            'entity_type' => 'task_priority',
            'entity_id' => null,
            'performed_by' => $this->user()?->public_id ?? 'system',
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Caching:**
- Cache key: `{tenant_slug}:task:priorities:all`
- TTL: 300s (warm tier)
- Invalidation: clear on any create/update/deactivate/reactivate

**Rules:** `coding-standards.md` — Caching (tenant-prefixed, warm 300s), Database Transactions (default swap), Error Handling (try/catch + structured context).

---

#### 4b. TaskService

**One-line summary:** Task CRUD, launch transaction (the most complex method), and lifecycle transitions with state machine validation.

**Key decisions:**
- `create()`: resolves `blueprint_id` and `priority_id` from `public_id`, stores Arabic fallback for `title_en`/`description_en`, validates Blueprint is active, auto-fills `priority_id` from default if omitted
- `launch()`: wrapped in `DB::transaction()`, validates Blueprint + stages + assignees, calls `AssignmentResolutionService`, creates stage/sub-stage instances, locks Blueprint, emits domain events
- `suspend()` / `resume()` / `cancel()`: validate state machine via `TaskStatus::canTransitionTo()`, wrapped in `DB::transaction()`, emit events
- Inline ABAC visibility in `list()` via `TaskVisibilityScope`

**Code snippet — launch (core transaction):**
```php
public function launch(Task $task, array $manualAssignments = []): Task
{
    try {
        return DB::transaction(function () use ($task, $manualAssignments) {
            if (!$task->isDraft()) {
                throw new TaskNotDraftException;
            }

            $blueprint = $task->blueprint;

            if (!$blueprint->is_active) {
                throw new BlueprintNotActiveException;
            }

            $stages = $blueprint->stages()->with('subStages')->orderBy('sequence_order')->get();

            if ($stages->isEmpty()) {
                throw new BlueprintHasNoStagesException;
            }

            // Lock blueprint on first launch
            if (!$blueprint->is_locked) {
                $blueprint->update(['is_locked' => true]);
                event(new \App\Modules\Blueprint\Events\BlueprintLocked($blueprint));
            }

            // Create Stage 1 instance
            $firstStage = $stages->first();
            $stageInstance = TaskStageInstance::create([
                'task_id' => $task->id,
                'blueprint_stage_id' => $firstStage->id,
                'sequence_order' => $firstStage->sequence_order,
                'completion_rule' => $firstStage->completion_rule->value,
                'status' => StageInstanceStatus::Active->value,
                'entered_at' => now(),
            ]);

            event(new StageInstanceCreated($stageInstance));

            // Create sub-stage instances for Stage 1
            $subStageInstances = [];
            foreach ($firstStage->subStages as $index => $subStage) {
                $subInstance = TaskSubStageInstance::create([
                    'task_id' => $task->id,
                    'parent_stage_instance_id' => $stageInstance->id,
                    'blueprint_sub_stage_id' => $subStage->id,
                    'sequence_order' => $subStage->sequence_order,
                    'is_required' => $subStage->is_required,
                    'completion_rule' => $subStage->completion_rule->value,
                    'status' => $index === 0
                        ? SubStageInstanceStatus::Active->value
                        : SubStageInstanceStatus::Pending->value,
                    'entered_at' => $index === 0 ? now() : null,
                ]);
                $subStageInstances[] = $subInstance;
                event(new SubStageInstanceCreated($subInstance));
            }

            // Resolve Stage 1 assignments
            $assignments = $this->assignmentResolutionService->resolveStageAssignments(
                $firstStage,
                $task,
                $stageInstance,
                $manualAssignments,
            );

            // Set owning department from first assignee
            if ($assignments->isNotEmpty()) {
                $firstAssignment = $assignments->first();
                $departmentId = $firstAssignment->position_id
                    ? Position::find($firstAssignment->position_id)?->department_id
                    : null;
                $stageInstance->update(['owning_department_id' => $departmentId]);
            }

            // Update task status
            $task->update([
                'status' => TaskStatus::Active,
                'launched_at' => now(),
            ]);

            event(new TaskLaunched($task));

            return $task->fresh(['stageInstances.assignments', 'priority', 'blueprint']);
        });
    } catch (TaskNotDraftException|BlueprintNotActiveException|BlueprintHasNoStagesException|
             UnresolvableAssignmentException|MissingManualAssignmentException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to launch task', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'task.launch',
            'entity_type' => 'task',
            'entity_id' => $task->public_id,
            'performed_by' => $this->user()?->public_id ?? 'system',
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Code snippet — suspend:**
```php
public function suspend(Task $task, string $reason): Task
{
    try {
        return DB::transaction(function () use ($task, $reason) {
            if (!$task->status->canTransitionTo(TaskStatus::Suspended)) {
                throw new InvalidTaskStateTransitionException('Cannot suspend task in ' . $task->status->name . ' status.');
            }

            $task->update([
                'status' => TaskStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            event(new TaskSuspended($task, $reason));

            return $task->fresh();
        });
    } catch (InvalidTaskStateTransitionException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to suspend task', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'task.suspend',
            'entity_type' => 'task',
            'entity_id' => $task->public_id,
            'performed_by' => $this->user()?->public_id ?? 'system',
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Test cases:**
1. Launch draft task with SpecificPosition assignment type, position is occupied → `status = active`, 1 stage instance, 1 assignment created
2. Launch draft task with ManualAtLaunch, no manual_assignments provided → throws `MissingManualAssignmentException`

**Rules:** `coding-standards.md` — Database Transactions (launch, suspend, resume, cancel), Error Handling (try/catch + module channel), Events (ShouldDispatchAfterCommit).

---

#### 4c. AssignmentResolutionService

**One-line summary:** Resolves Blueprint stage assignment rules to concrete users, checking active position occupants and delegation status.

**Key decisions:**
- Uses `Position::currentOccupant()` relationship (already defined in M2) for `SpecificPosition` and `DepartmentHead` types
- Calls `IamPolicy::resolveAssignee()` for out-of-office delegation check
- For `ManualAtLaunch`, validates user IDs exist and are active
- Returns a Collection of created `TaskStageAssignment` models
- Handles sub-stage assignments separately when sub-stages have their own assignment rules

**Code snippet — core resolution:**
```php
<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Blueprint\Enums\AssignmentType;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\AssignmentRole;
use App\Modules\Task\Exceptions\MissingManualAssignmentException;
use App\Modules\Task\Exceptions\UnresolvableAssignmentException;
use App\Modules\Task\Events\StageAssignmentCreated;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AssignmentResolutionService
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function resolveStageAssignments(
        BlueprintStage $blueprintStage,
        Task $task,
        TaskStageInstance $stageInstance,
        array $manualAssignments = [],
    ): Collection {
        $assignments = collect();

        $resolvedUser = $this->resolveUser($blueprintStage, $manualAssignments);

        // Check delegation
        $effectiveUser = $this->iamPolicy->resolveAssignee($resolvedUser);
        $delegatedFrom = $effectiveUser->id !== $resolvedUser->id ? $resolvedUser->id : null;
        $positionId = $resolvedUser->currentPositionAssignment?->position_id;

        $assignment = TaskStageAssignment::create([
            'task_id' => $task->id,
            'stage_instance_id' => $stageInstance->id,
            'sub_stage_instance_id' => null,
            'user_id' => $effectiveUser->id,
            'position_id' => $positionId,
            'delegated_from_user_id' => $delegatedFrom,
            'assignment_role' => AssignmentRole::Required->value,
            'is_completed' => false,
            'assigned_at' => now(),
        ]);

        event(new StageAssignmentCreated($assignment));
        $assignments->push($assignment);

        return $assignments;
    }

    private function resolveUser(BlueprintStage $blueprintStage, array $manualAssignments): User
    {
        return match ($blueprintStage->assignment_type) {
            AssignmentType::SpecificPosition => $this->resolveSpecificPosition($blueprintStage),
            AssignmentType::DepartmentHead => $this->resolveDepartmentHead($blueprintStage),
            AssignmentType::ManualAtLaunch => $this->resolveManual($blueprintStage, $manualAssignments),
        };
    }

    private function resolveSpecificPosition(BlueprintStage $stage): User
    {
        $occupant = $stage->assignedPosition?->currentOccupant;

        if (!$occupant) {
            throw new UnresolvableAssignmentException(
                "Position '{$stage->assignedPosition?->title_ar}' has no active occupant."
            );
        }

        return $occupant->user;
    }

    private function resolveDepartmentHead(BlueprintStage $stage): User
    {
        $headPosition = Position::where('department_id', $stage->assigned_department_id)
            ->where('is_department_head', true)
            ->active()
            ->first();

        if (!$headPosition) {
            throw new UnresolvableAssignmentException(
                "No department head position found for department."
            );
        }

        $occupant = $headPosition->currentOccupant;

        if (!$occupant) {
            throw new UnresolvableAssignmentException(
                "Department head position is vacant."
            );
        }

        return $occupant->user;
    }

    private function resolveManual(BlueprintStage $stage, array $manualAssignments): User
    {
        $stageAssignments = collect($manualAssignments)
            ->firstWhere('blueprint_stage_id', $stage->public_id);

        if (!$stageAssignments || empty($stageAssignments['user_ids'])) {
            throw new MissingManualAssignmentException(
                "Stage '{$stage->name_ar}' requires manual assignment but none were provided."
            );
        }

        $userId = $stageAssignments['user_ids'][0]; // First user for MVP
        $user = User::where('public_id', $userId)->where('is_active', true)->first();

        if (!$user) {
            throw new UnresolvableAssignmentException(
                "Manual assignee user not found or inactive."
            );
        }

        return $user;
    }
}
```

**Test cases:**
1. SpecificPosition: Blueprint stage with `assigned_position_id` pointing to occupied position → returns that user
2. SpecificPosition: Blueprint stage with `assigned_position_id` pointing to vacant position → throws `UnresolvableAssignmentException`

**Rules:** `coding-standards.md` — Error Handling (try/catch), Module Boundaries (Task calls IAM's `IamPolicy`, Organization's `Position` via relationships — not ORM joins).

---

### 5. Controllers

**One-line summary:** Thin controllers — validate → delegate to service → return API Resource. Use `HasRateLimiting` trait. Follow `BlueprintController` pattern.

**Key decisions:**
- `TaskPriorityController`: `LIST` rate limit on index, `MUTATE` on create/update/deactivate/reactivate
- `TaskController`: `LIST` on index, `MUTATE` on create/update/delete/launch/suspend/resume/cancel
- Task draft update/delete: check initiator or `task.manage` capability inline
- No dedicated `TaskLifecycleController` — lifecycle actions live on `TaskController` as it's the same resource

**Code snippet — TaskController:**
```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\StoreTaskRequest;
use App\Modules\Task\Requests\UpdateTaskRequest;
use App\Modules\Task\Requests\LaunchTaskRequest;
use App\Modules\Task\Requests\SuspendTaskRequest;
use App\Modules\Task\Requests\CancelTaskRequest;
use App\Modules\Task\Resources\TaskResource;
use App\Modules\Task\Resources\TaskDetailResource;
use App\Modules\Task\Services\TaskService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskService $taskService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);
        $tasks = $this->taskService->list($request);

        return TaskResource::collection($tasks);
    }

    public function show(Task $task): TaskDetailResource
    {
        $task->load([
            'priority', 'blueprint.category', 'initiator',
            'stageInstances.assignments.user',
            'stageInstances.subStageInstances',
        ]);

        return new TaskDetailResource($task);
    }

    public function store(StoreTaskRequest $request): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->create($request->validated(), $request->user());

        return new TaskResource($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->update($task, $request->validated(), $request->user());

        return new TaskResource($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->taskService->delete($task, $request->user());

        return response()->json(null, 204);
    }

    public function launch(LaunchTaskRequest $request, Task $task): TaskDetailResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->launch($task, $request->validated('manual_assignments', []));

        return new TaskDetailResource($task);
    }

    public function suspend(SuspendTaskRequest $request, Task $task): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->suspend($task, $request->validated('reason'));

        return new TaskResource($task);
    }

    public function resume(Request $request, Task $task): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->resume($task);

        return new TaskResource($task);
    }

    public function cancel(CancelTaskRequest $request, Task $task): TaskResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $task = $this->taskService->cancel($task, $request->validated('reason'));

        return new TaskResource($task);
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin, no business logic), Rate Limiting (HasRateLimiting trait, not route middleware).

---

### 6. Form Requests

**One-line summary:** Validation rules in dedicated Form Request classes. Use `Rule::enum()` for enum fields. `authorize()` returns `true` (ABAC handled by middleware or service).

**Key decisions:**
- `StoreTaskRequest`: validates `blueprint_id` exists as active Blueprint, `priority_id` optional (system default), `title_ar` required, `classification_level` via `Rule::enum(ClassificationLevel::class)`, `manual_assignments` array validation
- `UpdateTaskRequest`: same fields but all optional
- `LaunchTaskRequest`: only `manual_assignments` (optional array)
- `SuspendTaskRequest` / `CancelTaskRequest`: `reason` required string

**Code snippet — StoreTaskRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blueprint_id' => ['required', 'exists:blueprints,public_id'],
            'priority_id' => ['nullable', 'exists:task_priorities,public_id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['required', 'string'],
            'description_en' => ['nullable', 'string'],
            'classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'manual_assignments' => ['nullable', 'array'],
            'manual_assignments.*.blueprint_stage_id' => ['required_with:manual_assignments', 'string'],
            'manual_assignments.*.user_ids' => ['required_with:manual_assignments', 'array', 'min:1'],
            'manual_assignments.*.user_ids.*' => ['string', 'exists:users,public_id'],
        ];
    }
}
```

**Code snippet — SuspendTaskRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuspendTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Enum Usage (`Rule::enum()`), Validation (Form Request classes).

---

### 7. API Resources

**One-line summary:** Transform internal models to JSON. Expose `public_id` only. Bilingual fallback. Follow `BlueprintResource` pattern.

**Key decisions:**
- `TaskResource`: includes `priority`, `blueprint`, `initiator` info, all timestamps, status as enum
- `TaskDetailResource`: extends TaskResource info with stage instances and assignments (for show endpoint)
- All FK references use `public_id` resolution

**Code snippet — TaskResource:**
```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'blueprint_id' => $this->blueprint?->public_id,
            'priority' => new TaskPriorityResource($this->whenLoaded('priority')),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en ?? $this->title_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?? $this->description_ar,
            'classification_level' => $this->classification_level,
            'status' => $this->status,
            'initiator_id' => $this->initiator?->public_id,
            'due_date' => $this->due_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'launched_at' => $this->launched_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'resumed_at' => $this->resumed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources (`public_id` only, never internal `id`). N+1 prevention (eager load relationships used by the resource).

---

### 8. Events

**One-line summary:** All events implement `ShouldDispatchAfterCommit`. Use `Dispatchable` trait. Follow `BlueprintCreated` pattern exactly.

**Files (11 events):**
- `TaskCreated`, `TaskUpdated`, `TaskLaunched`, `TaskSuspended`, `TaskResumed`, `TaskCancelled`
- `StageInstanceCreated`, `SubStageInstanceCreated`, `StageAssignmentCreated`
- `TaskPriorityCreated`, `TaskPriorityUpdated`

**Code snippet — TaskLaunched:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskLaunched implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Task $task) {}
}
```

**Code snippet — TaskSuspended (includes reason):**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskSuspended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public string $reason,
    ) {}
}
```

**Rules:** `coding-standards.md` — Domain Events (`ShouldDispatchAfterCommit` is non-negotiable).

---

### 9. Exceptions

**One-line summary:** Domain exceptions extend `Exception`. Register renderable handlers in `bootstrap/app.php`. Follow `BlueprintLockedException` pattern.

**Files (9 exceptions):**
- `TaskNotDraftException`
- `TaskNotActiveException`
- `TaskNotSuspendedException`
- `BlueprintNotActiveException`
- `BlueprintHasNoStagesException`
- `UnresolvableAssignmentException`
- `MissingManualAssignmentException`
- `InvalidTaskStateTransitionException`
- `TaskAlreadyCancelledException`

**Code snippet — pattern:**
```php
<?php

namespace App\Modules\Task\Exceptions;

use Exception;

class TaskNotDraftException extends Exception
{
    public function __construct()
    {
        parent::__construct('Task is not in draft status.');
    }
}
```

**Registration in `bootstrap/app.php`:**
```php
// Task exceptions
$exceptions->renderable(fn (TaskNotDraftException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (TaskNotActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (TaskNotSuspendedException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (BlueprintNotActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (BlueprintHasNoStagesException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (UnresolvableAssignmentException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (MissingManualAssignmentException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (InvalidTaskStateTransitionException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (TaskAlreadyCancelledException $e) => response()->json(['message' => $e->getMessage()], 422));
```

**Rules:** `coding-standards.md` — Error Handling (register in `bootstrap/app.php`).

---

### 10. Routes

**One-line summary:** Route file under `routes/api/v1/tasks.php`. Use `auth:sanctum` + `capability:` middleware. Follow `blueprints.php` pattern.

**Code snippet — full routes file:**
```php
<?php

use App\Modules\Task\Controllers\TaskController;
use App\Modules\Task\Controllers\TaskPriorityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('tasks')->group(function () {
    // Task Priorities
    Route::get('priorities', [TaskPriorityController::class, 'index']);
    Route::middleware(['capability:task.manage_priorities'])->group(function () {
        Route::post('priorities', [TaskPriorityController::class, 'store']);
        Route::put('priorities/{priority}', [TaskPriorityController::class, 'update']);
        Route::post('priorities/{priority}/deactivate', [TaskPriorityController::class, 'deactivate']);
        Route::post('priorities/{priority}/reactivate', [TaskPriorityController::class, 'reactivate']);
    });

    // Tasks — CRUD (any authenticated user can create)
    Route::get('/', [TaskController::class, 'index']);
    Route::post('/', [TaskController::class, 'store']);
    Route::get('{task}', [TaskController::class, 'show']);
    Route::put('{task}', [TaskController::class, 'update']);        // initiator or task.manage
    Route::delete('{task}', [TaskController::class, 'destroy']);    // initiator only

    // Tasks — Launch (initiator or task.manage)
    Route::post('{task}/launch', [TaskController::class, 'launch']);

    // Tasks — Lifecycle (capability-protected)
    Route::middleware(['capability:task.suspend_resume'])->group(function () {
        Route::post('{task}/suspend', [TaskController::class, 'suspend']);
        Route::post('{task}/resume', [TaskController::class, 'resume']);
    });
    Route::middleware(['capability:task.cancel'])->group(function () {
        Route::post('{task}/cancel', [TaskController::class, 'cancel']);
    });
});
```

**Rules:** `coding-standards.md` — Rate Limiting (applied in controllers, not routes). Routes are kebab-case.

---

### 11. ABAC Task Visibility Scope

**One-line summary:** Eloquent query scope that builds WHERE clauses based on user capabilities for task listing. Avoids post-query filtering.

**Key decisions:**
- `task.view.organization`: no filtering (see all non-confidential tasks)
- `task.view.department_touched`: tasks where any stage instance has `owning_department_id` matching user's department
- `task.view.own_participation`: tasks where user is initiator OR has an assignment
- No capability: only own tasks (initiator or assignee)
- Confidential tasks: excluded unless user is participant or has `task.confidential.view_metadata`

**Code snippet:**
```php
<?php

namespace App\Modules\Task\Scopes;

use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Database\Eloquent\Builder;

class TaskVisibilityScope
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function apply(Builder $query, User $user): Builder
    {
        // Organization-wide visibility
        if ($this->iamPolicy->hasCapability($user, 'task.view.organization')) {
            return $this->applyConfidentialFilter($query, $user);
        }

        // Department-touched visibility
        $userDeptId = $user->currentPositionAssignment?->position?->department_id;

        $query->where(function (Builder $q) use ($user, $userDeptId) {
            // Always: own tasks (initiator)
            $q->where('initiator_user_id', $user->id);

            // Own assignments
            $q->orWhereHas('assignments', fn (Builder $aq) =>
                $aq->where('user_id', $user->id)
            );

            // Department-touched
            if ($this->iamPolicy->hasCapability($user, 'task.view.department_touched') && $userDeptId) {
                $q->orWhereHas('stageInstances', fn (Builder $sq) =>
                    $sq->where('owning_department_id', $userDeptId)
                );
            }
        });

        return $this->applyConfidentialFilter($query, $user);
    }

    private function applyConfidentialFilter(Builder $query, User $user): Builder
    {
        if ($this->iamPolicy->hasCapability($user, 'task.confidential.view_metadata')) {
            return $query; // Can see all
        }

        // Exclude confidential unless participant
        return $query->where(function (Builder $q) use ($user) {
            $q->where('classification_level', '!=', ClassificationLevel::Confidential->value)
              ->orWhere('initiator_user_id', $user->id)
              ->orWhereHas('assignments', fn (Builder $aq) =>
                  $aq->where('user_id', $user->id)
              );
        });
    }
}
```

**Rules:** `security-policy.md` — Confidential Tasks (named participants only). `coding-standards.md` — Performance (inline WHERE, not post-query).

---

### 12. Seeding

**One-line summary:** Seed 3 default priorities in `TenantDatabaseSeeder`. Add 2 new capabilities in `CapabilitySeeder`.

**Code snippet — TenantDatabaseSeeder addition:**
```php
use App\Modules\Task\Models\TaskPriority;

// After existing StageType::insert(...)
TaskPriority::insert([
    ['public_id' => (string) Str::uuid7(), 'name_en' => 'Critical', 'name_ar' => 'حرج', 'severity_rank' => 1, 'color_code' => '#DC2626', 'is_default' => false, 'is_active' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => (string) Str::uuid7(), 'name_en' => 'Urgent', 'name_ar' => 'عاجل', 'severity_rank' => 2, 'color_code' => '#F59E0B', 'is_default' => false, 'is_active' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => (string) Str::uuid7(), 'name_en' => 'Routine', 'name_ar' => 'روتيني', 'severity_rank' => 3, 'color_code' => '#10B981', 'is_default' => true, 'is_active' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
]);
```

**Code snippet — CapabilitySeeder new entries:**
```php
['key' => 'task.manage_priorities', 'name_ar' => 'إدارة أولويات المهام', 'name_en' => 'Manage Task Priorities', 'description' => 'Can create, update, deactivate, and reactivate task priority levels.'],
['key' => 'task.manage', 'name_ar' => 'إدارة المهام', 'name_en' => 'Manage Tasks', 'description' => 'Can update or delete other users\' draft tasks.'],
```

---

### 13. Logging

**One-line summary:** Add `task` channel to `config/logging.php`. Follow existing `blueprint` channel pattern.

**Code snippet:**
```php
'task' => [
    'driver' => 'daily',
    'path' => storage_path('logs/task.log'),
    'level' => 'debug',
    'days' => 14,
],
```

---

### 14. Factories

**One-line summary:** Create Pest-compatible factories for `TaskPriority`, `Task`, `TaskStageInstance`, and `TaskStageAssignment`.

**Code snippet — TaskFactory:**
```php
<?php

namespace Database\Factories;

use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'blueprint_id' => Blueprint::factory(),
            'priority_id' => TaskPriority::factory(),
            'title_ar' => fake()->sentence(),
            'title_en' => fake()->sentence(),
            'description_ar' => fake()->paragraph(),
            'description_en' => fake()->paragraph(),
            'classification_level' => ClassificationLevel::Public,
            'initiator_user_id' => User::factory(),
            'status' => TaskStatus::Draft,
            'due_date' => fake()->optional()->dateTimeBetween('now', '+30 days'),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Draft]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Active,
            'launched_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Suspended,
            'launched_at' => now()->subHour(),
            'suspended_at' => now(),
            'suspension_reason' => 'Test suspension',
        ]);
    }
}
```

---

### 15. Tests

**One-line summary:** Feature tests for all endpoints. Use factories. Test happy path + auth failure + validation failure + state transitions. Follow Pest conventions.

**Test cases — TaskPriorityTest:**
```php
// Test 1: List active priorities
test('can list active priorities', function () {
    TaskPriority::factory()->count(3)->create();
    $response = actingAs($user)->getJson('/api/v1/tasks/priorities');
    $response->assertOk()->assertJsonCount(3, 'data');
});

// Test 2: Create priority requires capability
test('creating priority without capability returns 403', function () {
    $response = actingAs($userWithoutCapability)->postJson('/api/v1/tasks/priorities', [
        'name_ar' => 'عاجل جداً',
        'severity_rank' => 1,
    ]);
    $response->assertForbidden();
});
```

**Test cases — TaskTest:**
```php
// Test 1: Create draft task
test('can create draft task from active blueprint', function () {
    $response = actingAs($user)->postJson('/api/v1/tasks', [
        'blueprint_id' => $blueprint->public_id,
        'title_ar' => 'مهمة جديدة',
        'description_ar' => 'وصف المهمة',
    ]);
    $response->assertCreated()
        ->assertJsonPath('data.status', TaskStatus::Draft->value)
        ->assertJsonPath('data.title_ar', 'مهمة جديدة');
});

// Test 2: Launch task locks blueprint
test('launching task locks the blueprint', function () {
    $response = actingAs($user)->postJson("/api/v1/tasks/{$task->public_id}/launch");
    $response->assertOk();
    expect($blueprint->fresh()->is_locked)->toBeTrue();
});
```

**Test cases — TaskLifecycleTest:**
```php
// Test 1: Suspend active task
test('can suspend active task with reason', function () {
    $response = actingAs($userWithCapability)->postJson("/api/v1/tasks/{$activeTask->public_id}/suspend", [
        'reason' => 'Pending external input',
    ]);
    $response->assertOk()
        ->assertJsonPath('data.status', TaskStatus::Suspended->value);
});

// Test 2: Cannot suspend draft task
test('cannot suspend draft task', function () {
    $response = actingAs($userWithCapability)->postJson("/api/v1/tasks/{$draftTask->public_id}/suspend", [
        'reason' => 'Test',
    ]);
    $response->assertUnprocessable();
});
```

**Rules:** `testing-policy.md` — Feature tests mandatory, use factories, test happy path + auth + validation.

---

## Execution Order

1. **Enums** — Create all 5 enum classes (no dependencies)
2. **Migrations** — Create all 5 migration files in `database/migrations/tenant/`
3. **Models** — Create all 5 model classes with relationships and casts
4. **Exceptions** — Create all 9 exception classes; register in `bootstrap/app.php`
5. **Events** — Create all 11 event classes
6. **Logging** — Add `task` channel to `config/logging.php`
7. **Scopes** — Create `TaskVisibilityScope`
8. **Services** — Create `AssignmentResolutionService`, `TaskPriorityService`, `TaskService` (in dependency order)
9. **Requests** — Create all 7 Form Request classes
10. **Resources** — Create all 6 API Resource classes
11. **Controllers** — Create `TaskPriorityController`, `TaskController`
12. **Factories** — Create all 4 factory classes
13. **Routes** — Create `routes/api/v1/tasks.php`; include in routing setup
14. **Seeding** — Update `TenantDatabaseSeeder` with default priorities; update `CapabilitySeeder` with new capabilities
15. **Tests** — Create all 4 feature test files
16. **Run tests** — `php artisan test --compact`
17. **Run Pint** — `vendor/bin/pint --dirty --format agent`

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/tasks/priorities` | Sanctum | — | List active priorities (full list, no pagination) |
| POST | `/api/v1/tasks/priorities` | Sanctum | `task.manage_priorities` | Create priority |
| PUT | `/api/v1/tasks/priorities/{priority}` | Sanctum | `task.manage_priorities` | Update priority |
| POST | `/api/v1/tasks/priorities/{priority}/deactivate` | Sanctum | `task.manage_priorities` | Deactivate priority |
| POST | `/api/v1/tasks/priorities/{priority}/reactivate` | Sanctum | `task.manage_priorities` | Reactivate priority |
| GET | `/api/v1/tasks` | Sanctum | — (ABAC filtered) | List tasks (cursor paginated) |
| POST | `/api/v1/tasks` | Sanctum | — (any auth user) | Create draft task |
| GET | `/api/v1/tasks/{task}` | Sanctum | — (ABAC filtered) | Show task detail |
| PUT | `/api/v1/tasks/{task}` | Sanctum | — (initiator or `task.manage`) | Update draft task |
| DELETE | `/api/v1/tasks/{task}` | Sanctum | — (initiator only) | Soft-delete draft task |
| POST | `/api/v1/tasks/{task}/launch` | Sanctum | — (initiator or `task.manage`) | Launch task |
| POST | `/api/v1/tasks/{task}/suspend` | Sanctum | `task.suspend_resume` | Suspend active task |
| POST | `/api/v1/tasks/{task}/resume` | Sanctum | `task.suspend_resume` | Resume suspended task |
| POST | `/api/v1/tasks/{task}/cancel` | Sanctum | `task.cancel` | Cancel task |

---

## What to Test Manually

1. **Happy path — full lifecycle:** Create draft task → update metadata → launch → verify stage instance + assignment created → suspend with reason → resume → cancel with reason.
2. **Blueprint lock:** Launch first task from Blueprint → verify `is_locked = true` on Blueprint → launch second task from same Blueprint → verify Blueprint stays locked.
3. **Assignment resolution — SpecificPosition:** Create Blueprint with `assignment_type = specific_position` → assign a position with an active occupant → launch task → verify assignment created with correct user.
4. **Assignment resolution — DepartmentHead:** Create Blueprint with `assignment_type = department_head` → launch → verify department head's current occupant is assigned.
5. **Assignment resolution — ManualAtLaunch:** Create Blueprint with `assignment_type = manual_at_launch` → launch without manual assignments → verify 422 error → launch with manual assignments → verify success.
6. **Delegation:** Set original assignee as out-of-office with delegate → launch task → verify assignment goes to delegate with `delegated_from_user_id` set.
7. **Vacant position:** Remove occupant from assigned position → launch task → verify `UnresolvableAssignmentException` (422).
8. **State machine validation:** Try to suspend a draft task → 422. Try to resume an active task → 422. Try to cancel a completed task → 422.
9. **ABAC visibility:** User A creates task → User B (no capabilities) lists tasks → verify User B cannot see User A's task (unless assigned or department-touched). User C (with `task.view.organization`) lists tasks → verify they see all non-confidential tasks.
10. **Confidential filtering:** Create confidential task → verify non-participant without override capability cannot see it. Verify participant (initiator/assignee) can see it.
11. **Priority management:** Create priority → set as default → verify old default unset → deactivate → verify excluded from list → reactivate → verify re-included.
12. **Rate limiting:** Hit task create endpoint 31 times in 1 minute → verify 429 on 31st request.
13. **Caching:** List priorities → create new priority → list again → verify new priority appears (cache invalidated).
14. **Cursor pagination:** Create 25 tasks → request first page (`per_page=10`) → verify `next_cursor` present → request second page → verify 10 more results → request third page → verify `has_more = false`.
15. **Draft-only mutations:** Launch a task → try PUT update → verify 422 "Task is not in draft status". Try DELETE → verify 422.
16. **Inactive blueprint:** Deactivate Blueprint → try creating task from it → verify 422. Try launching existing draft → verify 422.
