# Plan: Stage Lifecycle

> **Spec:** 006-stage-lifecycle
> **Date:** 2026-06-12
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| **Completion note strategy** — per-assignment or stage-level? | **Per-assignment via new `completion_note` column on `task_stage_assignments`.** Stage-level `completion_note` receives the last completing assignee's note as a convenience copy. | Preserves individual accountability. Under `AllAssignees` rule, each assignee's note is independently recorded. |
| **Sub-stage return transitions** — explicit table or sequence_order comparison? | **Sequence_order comparison.** Any active sub-stage can return to any earlier sub-stage within the same parent stage. | Blueprint transitions only define stage-to-stage routes. Sub-stages are internal steps — explicit transition modeling is over-engineering for MVP. |
| **Manual-at-launch stages on re-entry** — reuse original or require fresh input? | **Reuse the original manual assignments from the task's first launch.** Look up previous `task_stage_assignments` for the same `blueprint_stage_id` on this task. | Users selected assignees at task creation. If a change is needed after re-entry, the assignment override endpoint is available. |
| **Advance transition lookup** — require explicit transition or fall back to sequence_order? | **Fall back to next `sequence_order` if no explicit advance transition exists.** Check `blueprint_stage_transitions` first; if no advance transition found, use `sequence_order + 1`. | Matches ERD note: "MVP: advance is always to next sequence_order." Explicit transitions are optional routing overrides. |
| **New capability `task.advance_stage`?** | **No.** Completing/advancing a stage is implicit for assigned users. | The assignment itself is the authorization. Adding a separate capability creates unnecessary permission overhead. |
| **New column `completion_note` on `task_stage_assignments`?** | **Yes.** Add via additive migration. | Minor schema change — one nullable text column. Enables per-assignee notes without changing existing data. |

---

## Technical Approach

Add a `StageLifecycleService` to `app/Modules/Task/Services/` and a `StageLifecycleController` to `app/Modules/Task/Controllers/`, reusing all existing models, enums, and the `AssignmentResolutionService` from Spec 005. One additive migration adds `completion_note` to `task_stage_assignments`. Seven new domain exceptions, eight new domain events. Four new form requests. Two new API resources for history/timeline views. Routes appended to existing `routes/api/v1/tasks.php`.

**Key decisions:**
- **Dedicated `StageLifecycleService`** — isolates the complex stage progression logic from `TaskService` which already handles CRUD/launch/lifecycle. Keeps both services focused and testable.
- **Reuse `AssignmentResolutionService`** — stage advance and return both need to resolve next-stage assignees. The existing service's `resolveStageAssignments()` and `resolveSubStageAssignments()` methods are called directly.
- **Completion rule evaluation as a private method** — `evaluateCompletionRule()` is shared logic used by both stage and sub-stage completion. Centralized in `StageLifecycleService`.
- **Manual-at-launch re-entry via historical assignment lookup** — when a `manual_at_launch` stage is re-entered, the service queries previous `TaskStageAssignment` records for the same `blueprint_stage_id` on this task to find the original user IDs, then passes them to `AssignmentResolutionService`.
- **Timeline constructed from DB queries, not stored events** — the timeline endpoint aggregates `task_stage_instances` and `task_stage_assignments` ordered by timestamp. No separate timeline table needed.
- **No caching for stage instance data** — write-heavy with frequent status changes; direct DB queries are fast (single-task scope with indexed FKs).
- **No new enums** — all existing enums from Spec 005 and Blueprint module cover every needed status and type.

---

## Affected Modules / Files

### New Files (to create)

| File | Purpose |
|------|---------|
| **Migration** | |
| `database/migrations/tenant/2026_06_12_000001_add_completion_note_to_task_stage_assignments_table.php` | Add `completion_note` text nullable column |
| **Service** | |
| `app/Modules/Task/Services/StageLifecycleService.php` | Stage/sub-stage complete, return, assignment override, history, timeline |
| **Controller** | |
| `app/Modules/Task/Controllers/StageLifecycleController.php` | 10 endpoints for stage lifecycle operations |
| **Requests** | |
| `app/Modules/Task/Requests/CompleteStageRequest.php` | Validate `completion_note` (optional text) |
| `app/Modules/Task/Requests/ReturnStageRequest.php` | Validate `target_stage_id` (required UUID), `reason` (required text) |
| `app/Modules/Task/Requests/ReturnSubStageRequest.php` | Validate `target_sub_stage_id` (required UUID), `reason` (required text) |
| `app/Modules/Task/Requests/OverrideAssignmentRequest.php` | Validate `assignments` array, `reason` (required text) |
| **Resources** | |
| `app/Modules/Task/Resources/StageReturnResource.php` | Return history JSON shape |
| `app/Modules/Task/Resources/TaskTimelineResource.php` | Timeline entry JSON shape |
| **Events** | |
| `app/Modules/Task/Events/StageAssignmentCompleted.php` | Individual assignment completed |
| `app/Modules/Task/Events/StageInstanceCompleted.php` | Stage completed (all rules satisfied) |
| `app/Modules/Task/Events/StageInstanceAdvanced.php` | Task moved to next stage |
| `app/Modules/Task/Events/StageInstanceReturned.php` | Stage returned to earlier stage |
| `app/Modules/Task/Events/SubStageAssignmentCompleted.php` | Sub-stage assignment completed |
| `app/Modules/Task/Events/SubStageInstanceCompleted.php` | Sub-stage completed |
| `app/Modules/Task/Events/SubStageInstanceReturned.php` | Sub-stage returned |
| `app/Modules/Task/Events/StageAssignmentOverridden.php` | Assignment overridden |
| `app/Modules/Task/Events/TaskCompleted.php` | Task completed (final stage done) |
| **Exceptions** | |
| `app/Modules/Task/Exceptions/StageNotActiveException.php` | 422 — stage is not active |
| `app/Modules/Task/Exceptions/SubStageNotActiveException.php` | 422 — sub-stage is not active |
| `app/Modules/Task/Exceptions/UserNotAssigneeException.php` | 422 — user not assigned to stage |
| `app/Modules/Task/Exceptions/InvalidReturnTargetException.php` | 422 — return target not in transitions |
| `app/Modules/Task/Exceptions/RequiredSubStagesIncompleteException.php` | 422 — required sub-stages not done |
| `app/Modules/Task/Exceptions/InvalidSubStageReturnTargetException.php` | 422 — sub-stage return target invalid |
| `app/Modules/Task/Exceptions/AssigneeNotFoundForOverrideException.php` | 422 — user not an active assignee |
| **Tests** | |
| `tests/Feature/Modules/Task/StageCompleteTest.php` | Stage completion + advance tests |
| `tests/Feature/Modules/Task/StageReturnTest.php` | Stage/sub-stage return tests |
| `tests/Feature/Modules/Task/SubStageCompleteTest.php` | Sub-stage completion tests |
| `tests/Feature/Modules/Task/AssignmentOverrideTest.php` | Assignment override tests |
| `tests/Feature/Modules/Task/StageHistoryTest.php` | Stage history + timeline endpoint tests |

### Modified Files (to edit)

| File | Change |
|------|--------|
| `app/Modules/Task/Models/TaskStageAssignment.php` | Add `completion_note` to `#[Fillable]` |
| `routes/api/v1/tasks.php` | Add stage lifecycle routes |

---

## Implementation Notes

### 1. Migration — Add `completion_note` to `task_stage_assignments`

**One-line summary:** Single additive migration adding a nullable text column for per-assignee completion notes.

**Key decisions:**
- Additive only — no column removal, no data transformation
- Nullable text — matches the pattern used on `task_stage_instances.completion_note`

**File:** `database/migrations/tenant/2026_06_12_000001_add_completion_note_to_task_stage_assignments_table.php`

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
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->text('completion_note')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_stage_assignments', function (Blueprint $table) {
            $table->dropColumn('completion_note');
        });
    }
};
```

**Model update** — add `'completion_note'` to `TaskStageAssignment`'s `#[Fillable]` attribute:
```php
#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'user_id',
    'position_id', 'delegated_from_user_id', 'assignment_role',
    'is_completed', 'assigned_at', 'completed_at', 'completion_note',
    'reassigned_at', 'reassigned_by_user_id', 'reassignment_reason',
])]
```

**Rules:** `coding-standards.md` — Migrations. Additive only, no data loss.

---

### 2. Exceptions

**One-line summary:** Seven new domain exceptions extending `App\Exceptions\DomainException`. No registration needed — `bootstrap/app.php` already catches all `DomainException` subclasses via `$exceptions->renderable(fn (DomainException $e) => $e->render())`.

**Key decisions:**
- All extend `DomainException` (statusCode defaults to 422)
- Match the exact pattern from existing `TaskNotActiveException`
- No changes to `bootstrap/app.php` required

**Code snippet — pattern (all 7 follow this):**
```php
<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class StageNotActiveException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Stage instance is not in active status.');
    }
}
```

**Exception messages:**
| Exception | Message |
|-----------|---------|
| `StageNotActiveException` | `Stage instance is not in active status.` |
| `SubStageNotActiveException` | `Sub-stage instance is not in active status.` |
| `UserNotAssigneeException` | `User is not an active assignee of this stage.` |
| `InvalidReturnTargetException` | `Invalid return target: no return transition defined for this stage.` |
| `RequiredSubStagesIncompleteException` | `Cannot complete stage: required sub-stages are not all completed.` |
| `InvalidSubStageReturnTargetException` | `Invalid sub-stage return target: must be an earlier sub-stage in the same parent stage.` |
| `AssigneeNotFoundForOverrideException` | `User is not an active assignee that can be overridden.` |

**Test cases:**
1. `throw new StageNotActiveException` → renders `{"message": "Stage instance is not in active status."}` with HTTP 422
2. All exceptions are `instanceof DomainException` → `true`

**Rules:** `coding-standards.md` — Error Handling.

---

### 3. Events

**One-line summary:** Nine new events (8 new + reusing `StageAssignmentCreated` from Spec 005). All implement `ShouldDispatchAfterCommit`. Follow existing `StageInstanceCreated` pattern exactly.

**Key decisions:**
- `TaskCompleted` carries only the `Task` model (matches `TaskLaunched` pattern)
- `StageInstanceAdvanced` carries the completed stage instance AND the new stage instance for downstream consumers
- `StageAssignmentOverridden` carries old assignment, new assignment, and reason
- `StageInstanceReturned` carries the returned stage instance and the reason
- `StageAssignmentCompleted` carries the assignment and optional completion note

**Files (9 new events):**
- `StageAssignmentCompleted.php`
- `StageInstanceCompleted.php`
- `StageInstanceAdvanced.php`
- `StageInstanceReturned.php`
- `SubStageAssignmentCompleted.php`
- `SubStageInstanceCompleted.php`
- `SubStageInstanceReturned.php`
- `StageAssignmentOverridden.php`
- `TaskCompleted.php`

**Code snippet — StageInstanceAdvanced:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceAdvanced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $completedStageInstance,
        public TaskStageInstance $newStageInstance,
    ) {}
}
```

**Code snippet — StageAssignmentCompleted:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageAssignmentCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public TaskStageAssignment $assignment) {}
}
```

**Code snippet — StageInstanceReturned:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $returnedStageInstance,
        public string $reason,
    ) {}
}
```

**Code snippet — StageAssignmentOverridden:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageAssignmentOverridden implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageAssignment $oldAssignment,
        public TaskStageAssignment $newAssignment,
        public string $reason,
    ) {}
}
```

**Code snippet — TaskCompleted:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Task $task) {}
}
```

**Remaining events follow single-model pattern:**
- `StageInstanceCompleted(TaskStageInstance $stageInstance)`
- `SubStageAssignmentCompleted(TaskStageAssignment $assignment)`
- `SubStageInstanceCompleted(TaskSubStageInstance $subStageInstance)`
- `SubStageInstanceReturned(TaskSubStageInstance $returnedSubStageInstance, string $reason)`

**Rules:** `coding-standards.md` — Domain Events (`ShouldDispatchAfterCommit` is non-negotiable).

---

### 4. Form Requests

**One-line summary:** Four new form requests. `authorize()` returns `true` (ABAC checked in service). Match existing `SuspendTaskRequest`/`CancelTaskRequest` pattern.

**Code snippet — CompleteStageRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'completion_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
```

**Code snippet — ReturnStageRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_stage_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
```

**Code snippet — ReturnSubStageRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnSubStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_sub_stage_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
```

**Code snippet — OverrideAssignmentRequest:**
```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.current_user_id' => ['required', 'string', 'uuid'],
            'assignments.*.new_user_id' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation (Form Request classes). `authorize()` returns `true`; ABAC in service.

---

### 5. StageLifecycleService — Core Business Logic

**One-line summary:** Dedicated service for stage progression — complete stage/sub-stage (with completion rule evaluation), return stage/sub-stage, assignment override, and read-only history/timeline queries. Uses `DB::transaction()` for all multi-write operations, try/catch with `Log::channel('task')`.

**Key decisions:**
- Constructor injects `AssignmentResolutionService`, `IamPolicy`, `TaskVisibilityScope`
- Uses `AuthenticatedUser` trait for `$this->user()`
- All mutating methods follow the try/catch pattern from `TaskService`
- Completion rule evaluation is a reusable private method
- Stage advance determines next stage by: (1) check `blueprint_stage_transitions` for explicit advance, (2) fall back to `blueprint_stages` with next `sequence_order`
- Manual-at-launch re-entry: query previous assignments for same `blueprint_stage_id` on this task

**File:** `app/Modules/Task/Services/StageLifecycleService.php`

#### 5a. `completeStage()` — Core stage completion & auto-advance

**Signature:**
```php
public function completeStage(Task $task, TaskStageInstance $stageInstance, User $user, ?string $completionNote = null): TaskStageInstance
```

**Logic flow:**
1. Validate: task is `Active`, stage instance is `Active`, user has an active (non-completed, non-reassigned) assignment on this stage instance
2. Mark the user's assignment: `is_completed = true`, `completed_at = now()`, `completion_note = $completionNote`
3. Emit `StageAssignmentCompleted` event
4. Call `evaluateCompletionRule($stageInstance)` — returns `true` if stage should complete
5. If completion rule NOT satisfied → return (partial completion, waiting for other assignees)
6. If stage has sub-stages → verify all required sub-stages are `Completed`, else throw `RequiredSubStagesIncompleteException`
7. Mark stage: `status = Completed`, `exited_at = now()`, `completion_note = $completionNote` (last writer)
8. Emit `StageInstanceCompleted`
9. Determine next stage:
   - Query `BlueprintTransition::where('from_stage_id', $stageInstance->blueprint_stage_id)->where('transition_type', TransitionType::Advance)->first()`
   - If found → use `to_stage_id`
   - If not found → query `BlueprintStage::where('blueprint_id', $task->blueprint_id)->where('sequence_order', '>', $currentSequenceOrder)->orderBy('sequence_order')->first()`
   - If no next stage → final stage: complete the task
10. If next stage exists → call `advanceToStage()` (private helper)
11. If final stage → `$task->update(['status' => TaskStatus::Completed, 'completed_at' => now()])`, emit `TaskCompleted`
12. Entire operation wrapped in `DB::transaction()`

**Code snippet — completeStage:**
```php
public function completeStage(Task $task, TaskStageInstance $stageInstance, User $user, ?string $completionNote = null): TaskStageInstance
{
    try {
        return DB::transaction(function () use ($task, $stageInstance, $user, $completionNote) {
            if (! $task->isActive()) {
                throw new TaskNotActiveException;
            }

            if ($stageInstance->status !== StageInstanceStatus::Active) {
                throw new StageNotActiveException;
            }

            // Find user's active assignment
            $assignment = $stageInstance->assignments()
                ->where('user_id', $user->id)
                ->where('is_completed', false)
                ->whereNull('reassigned_at')
                ->first();

            if (! $assignment) {
                throw new UserNotAssigneeException;
            }

            // Mark assignment complete
            $assignment->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completion_note' => $completionNote,
            ]);

            event(new StageAssignmentCompleted($assignment));

            // Evaluate completion rule
            if (! $this->evaluateCompletionRule($stageInstance)) {
                return $stageInstance->fresh(['assignments']);
            }

            // Check required sub-stages
            $hasSubStages = $stageInstance->subStageInstances()->exists();
            if ($hasSubStages) {
                $incompleteRequired = $stageInstance->subStageInstances()
                    ->where('is_required', true)
                    ->where('status', '!=', SubStageInstanceStatus::Completed->value)
                    ->exists();

                if ($incompleteRequired) {
                    throw new RequiredSubStagesIncompleteException;
                }
            }

            // Complete stage
            $stageInstance->update([
                'status' => StageInstanceStatus::Completed,
                'exited_at' => now(),
                'completion_note' => $completionNote,
            ]);

            event(new StageInstanceCompleted($stageInstance));

            // Determine next stage
            $nextBlueprintStage = $this->resolveNextStage($task, $stageInstance);

            if ($nextBlueprintStage) {
                $newStageInstance = $this->advanceToStage($task, $stageInstance, $nextBlueprintStage);
                event(new StageInstanceAdvanced($stageInstance, $newStageInstance));

                return $newStageInstance->fresh(['assignments', 'subStageInstances']);
            }

            // Final stage — complete the task
            $task->update([
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
            ]);

            event(new TaskCompleted($task));

            return $stageInstance->fresh(['assignments']);
        });
    } catch (TaskNotActiveException|StageNotActiveException|UserNotAssigneeException|RequiredSubStagesIncompleteException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to complete stage', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'stage.complete',
            'entity_type' => 'task_stage_instance',
            'entity_id' => $stageInstance->id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

#### 5b. `evaluateCompletionRule()` — Shared completion rule evaluation

```php
private function evaluateCompletionRule(TaskStageInstance|TaskSubStageInstance $instance): bool
{
    $assignments = $instance->assignments()
        ->whereNull('reassigned_at')
        ->get();

    return match ($instance->completion_rule) {
        CompletionRule::AnyAssignee => $assignments
            ->whereIn('assignment_role', [AssignmentRole::Required, AssignmentRole::Lead])
            ->where('is_completed', true)
            ->isNotEmpty(),

        CompletionRule::AllAssignees => $assignments
            ->whereIn('assignment_role', [AssignmentRole::Required, AssignmentRole::Lead])
            ->every(fn ($a) => $a->is_completed),

        CompletionRule::LeadAssignee => $assignments
            ->where('assignment_role', AssignmentRole::Lead)
            ->where('is_completed', true)
            ->isNotEmpty(),
    };
}
```

#### 5c. `resolveNextStage()` — Determine next stage via transitions or sequence_order fallback

```php
private function resolveNextStage(Task $task, TaskStageInstance $stageInstance): ?BlueprintStage
{
    // 1. Check explicit advance transition
    $transition = BlueprintTransition::where('blueprint_id', $task->blueprint_id)
        ->where('from_stage_id', $stageInstance->blueprint_stage_id)
        ->where('transition_type', TransitionType::Advance->value)
        ->first();

    if ($transition) {
        return BlueprintStage::find($transition->to_stage_id);
    }

    // 2. Fall back to next sequence_order
    return BlueprintStage::where('blueprint_id', $task->blueprint_id)
        ->where('sequence_order', '>', $stageInstance->sequence_order)
        ->orderBy('sequence_order')
        ->first();
}
```

#### 5d. `advanceToStage()` — Create next stage instance and resolve assignments

```php
private function advanceToStage(Task $task, TaskStageInstance $completedInstance, BlueprintStage $nextBlueprintStage): TaskStageInstance
{
    $newStageInstance = TaskStageInstance::create([
        'task_id' => $task->id,
        'blueprint_stage_id' => $nextBlueprintStage->id,
        'sequence_order' => $nextBlueprintStage->sequence_order,
        'completion_rule' => $nextBlueprintStage->completion_rule->value,
        'status' => StageInstanceStatus::Active->value,
        'entered_at' => now(),
    ]);

    event(new StageInstanceCreated($newStageInstance));

    // Create sub-stage instances
    $nextBlueprintStage->load('subStages');
    foreach ($nextBlueprintStage->subStages as $index => $subStage) {
        $subInstance = TaskSubStageInstance::create([
            'task_id' => $task->id,
            'parent_stage_instance_id' => $newStageInstance->id,
            'blueprint_sub_stage_id' => $subStage->id,
            'sequence_order' => $subStage->sequence_order,
            'is_required' => $subStage->is_required,
            'completion_rule' => $subStage->completion_rule->value,
            'status' => $index === 0
                ? SubStageInstanceStatus::Active->value
                : SubStageInstanceStatus::Pending->value,
            'entered_at' => $index === 0 ? now() : null,
        ]);
        event(new SubStageInstanceCreated($subInstance));

        // Resolve sub-stage assignments for active sub-stage
        if ($index === 0 && $subStage->assignment_type !== null) {
            $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $subStage);
            $this->assignmentResolutionService->resolveSubStageAssignments(
                $subStage, $task, $subInstance, $manualAssignments,
            );
        }
    }

    // Resolve stage assignments
    $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $nextBlueprintStage);
    $assignments = $this->assignmentResolutionService->resolveStageAssignments(
        $nextBlueprintStage, $task, $newStageInstance, $manualAssignments,
    );

    // Set owning department from first assignee
    if ($assignments->isNotEmpty()) {
        $departmentId = $assignments->first()->position_id
            ? Position::find($assignments->first()->position_id)?->department_id
            : null;
        $newStageInstance->update(['owning_department_id' => $departmentId]);
    }

    return $newStageInstance;
}
```

#### 5e. `resolveManualAssignmentsForReentry()` — Reuse original manual assignments

```php
private function resolveManualAssignmentsForReentry(Task $task, BlueprintStage|BlueprintSubStage $stage): array
{
    if ($stage->assignment_type !== AssignmentType::ManualAtLaunch) {
        return [];
    }

    // Look up previous assignments for this blueprint stage on this task
    $previousAssignments = TaskStageAssignment::where('task_id', $task->id)
        ->whereHas('stageInstance', fn ($q) => $q->where('blueprint_stage_id', $stage->id))
        ->whereNull('reassigned_at')
        ->with('user')
        ->get();

    if ($previousAssignments->isEmpty()) {
        return [];
    }

    $userPublicIds = $previousAssignments->pluck('user.public_id')->filter()->values()->all();

    $key = $stage instanceof BlueprintSubStage ? 'blueprint_sub_stage_id' : 'blueprint_stage_id';

    return [
        [
            $key => $stage->public_id,
            'user_ids' => $userPublicIds,
        ],
    ];
}
```

#### 5f. `returnStage()` — Return to earlier stage

**Signature:**
```php
public function returnStage(Task $task, TaskStageInstance $stageInstance, User $user, string $targetStagePublicId, string $reason): TaskStageInstance
```

**Logic flow:**
1. Validate: task `Active`, stage `Active`, user is active assignee
2. Resolve target stage: `BlueprintStage::where('public_id', $targetStagePublicId)->first()`
3. Validate return transition exists in `blueprint_stage_transitions` (or target has lower `sequence_order` — see open question resolution: we validate explicit transitions)
4. Mark current stage `status = Returned`, `exited_at = now()`, `return_reason = $reason`
5. Cancel active/pending sub-stages of current stage: `status = Returned`, `exited_at = now()`
6. Create new `TaskStageInstance` for target stage with `status = Active`, `entered_at = now()`
7. Create sub-stage instances for target stage, resolve assignments
8. Emit `StageInstanceReturned`, `StageInstanceCreated`, `StageAssignmentCreated`

**Code snippet — returnStage:**
```php
public function returnStage(Task $task, TaskStageInstance $stageInstance, User $user, string $targetStagePublicId, string $reason): TaskStageInstance
{
    try {
        return DB::transaction(function () use ($task, $stageInstance, $user, $targetStagePublicId, $reason) {
            if (! $task->isActive()) {
                throw new TaskNotActiveException;
            }

            if ($stageInstance->status !== StageInstanceStatus::Active) {
                throw new StageNotActiveException;
            }

            $assignment = $stageInstance->assignments()
                ->where('user_id', $user->id)
                ->where('is_completed', false)
                ->whereNull('reassigned_at')
                ->first();

            if (! $assignment) {
                throw new UserNotAssigneeException;
            }

            // Resolve target blueprint stage
            $targetBlueprintStage = BlueprintStage::where('public_id', $targetStagePublicId)->first();
            if (! $targetBlueprintStage) {
                throw new InvalidReturnTargetException;
            }

            // Validate return transition exists
            $returnTransition = BlueprintTransition::where('blueprint_id', $task->blueprint_id)
                ->where('from_stage_id', $stageInstance->blueprint_stage_id)
                ->where('to_stage_id', $targetBlueprintStage->id)
                ->where('transition_type', TransitionType::Return->value)
                ->first();

            if (! $returnTransition) {
                throw new InvalidReturnTargetException;
            }

            // Mark current stage as returned
            $stageInstance->update([
                'status' => StageInstanceStatus::Returned,
                'exited_at' => now(),
                'return_reason' => $reason,
            ]);

            // Cancel active/pending sub-stages
            $stageInstance->subStageInstances()
                ->whereIn('status', [SubStageInstanceStatus::Active->value, SubStageInstanceStatus::Pending->value])
                ->update(['status' => SubStageInstanceStatus::Returned->value, 'exited_at' => now()]);

            event(new StageInstanceReturned($stageInstance, $reason));

            // Create new stage instance for target
            $newStageInstance = $this->advanceToStage($task, $stageInstance, $targetBlueprintStage);

            return $newStageInstance->fresh(['assignments', 'subStageInstances']);
        });
    } catch (TaskNotActiveException|StageNotActiveException|UserNotAssigneeException|InvalidReturnTargetException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to return stage', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'stage.return',
            'entity_type' => 'task_stage_instance',
            'entity_id' => $stageInstance->id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

#### 5g. `completeSubStage()` — Sub-stage completion

**Signature:**
```php
public function completeSubStage(Task $task, TaskSubStageInstance $subStageInstance, User $user, ?string $completionNote = null): TaskSubStageInstance
```

**Logic flow:**
1. Validate: task `Active`, sub-stage `Active`, user is active assignee of sub-stage
2. Mark assignment complete, emit `SubStageAssignmentCompleted`
3. Evaluate sub-stage completion rule
4. If not satisfied → return
5. Mark sub-stage `Completed`, `exited_at = now()`, emit `SubStageInstanceCompleted`
6. Find next sub-stage by `sequence_order` within same parent stage
7. If next sub-stage exists → set `Active`, `entered_at = now()`, resolve assignments, emit `SubStageInstanceCreated`
8. If no next sub-stage → return (stage-level completion handles stage advancement)
9. Wrapped in `DB::transaction()`

**Code snippet — completeSubStage:**
```php
public function completeSubStage(Task $task, TaskSubStageInstance $subStageInstance, User $user, ?string $completionNote = null): TaskSubStageInstance
{
    try {
        return DB::transaction(function () use ($task, $subStageInstance, $user, $completionNote) {
            if (! $task->isActive()) {
                throw new TaskNotActiveException;
            }

            if ($subStageInstance->status !== SubStageInstanceStatus::Active) {
                throw new SubStageNotActiveException;
            }

            $assignment = $subStageInstance->assignments()
                ->where('user_id', $user->id)
                ->where('is_completed', false)
                ->whereNull('reassigned_at')
                ->first();

            if (! $assignment) {
                throw new UserNotAssigneeException;
            }

            $assignment->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completion_note' => $completionNote,
            ]);

            event(new SubStageAssignmentCompleted($assignment));

            if (! $this->evaluateCompletionRule($subStageInstance)) {
                return $subStageInstance->fresh(['assignments']);
            }

            $subStageInstance->update([
                'status' => SubStageInstanceStatus::Completed,
                'exited_at' => now(),
                'completion_note' => $completionNote,
            ]);

            event(new SubStageInstanceCompleted($subStageInstance));

            // Activate next sub-stage
            $parentStageInstance = $subStageInstance->parentStageInstance;
            $nextBlueprintSubStage = BlueprintSubStage::where('blueprint_stage_id', $parentStageInstance->blueprint_stage_id)
                ->where('sequence_order', '>', $subStageInstance->sequence_order)
                ->orderBy('sequence_order')
                ->first();

            if ($nextBlueprintSubStage) {
                $nextSubInstance = TaskSubStageInstance::create([
                    'task_id' => $task->id,
                    'parent_stage_instance_id' => $parentStageInstance->id,
                    'blueprint_sub_stage_id' => $nextBlueprintSubStage->id,
                    'sequence_order' => $nextBlueprintSubStage->sequence_order,
                    'is_required' => $nextBlueprintSubStage->is_required,
                    'completion_rule' => $nextBlueprintSubStage->completion_rule->value,
                    'status' => SubStageInstanceStatus::Active->value,
                    'entered_at' => now(),
                ]);

                event(new SubStageInstanceCreated($nextSubInstance));

                if ($nextBlueprintSubStage->assignment_type !== null) {
                    $manualAssignments = $this->resolveManualAssignmentsForReentry($task, $nextBlueprintSubStage);
                    $this->assignmentResolutionService->resolveSubStageAssignments(
                        $nextBlueprintSubStage, $task, $nextSubInstance, $manualAssignments,
                    );
                }
            }

            return $subStageInstance->fresh(['assignments']);
        });
    } catch (TaskNotActiveException|SubStageNotActiveException|UserNotAssigneeException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to complete sub-stage', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'sub_stage.complete',
            'entity_type' => 'task_sub_stage_instance',
            'entity_id' => $subStageInstance->id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

#### 5h. `returnSubStage()` — Sub-stage return

**Signature:**
```php
public function returnSubStage(Task $task, TaskSubStageInstance $subStageInstance, User $user, string $targetSubStagePublicId, string $reason): TaskSubStageInstance
```

**Logic flow:**
1. Validate: task `Active`, sub-stage `Active`, user is active assignee
2. Resolve target: `BlueprintSubStage::where('public_id', $targetSubStagePublicId)->first()`
3. Validate: target belongs to same parent stage AND has lower `sequence_order`
4. Mark current sub-stage `Returned`, `exited_at = now()`
5. Create new `TaskSubStageInstance` for target, `Active`, `entered_at = now()`
6. Resolve assignments for new sub-stage instance
7. Emit `SubStageInstanceReturned`, `SubStageInstanceCreated`, `StageAssignmentCreated`
8. Wrapped in `DB::transaction()`

#### 5i. `overrideStageAssignment()` — Stage assignment override

**Signature:**
```php
public function overrideStageAssignment(Task $task, TaskStageInstance $stageInstance, User $callerUser, array $assignments, string $reason): TaskStageInstance
```

**Logic flow:**
1. Validate: task `Active`, stage `Active`, caller has `task.override_assignment` capability
2. For each `{current_user_id, new_user_id}`:
   - Resolve `current_user_id` → find internal user ID
   - Find active assignment for this user on stage instance
   - If not found → throw `AssigneeNotFoundForOverrideException`
   - Set `reassigned_at = now()`, `reassigned_by_user_id = $callerUser->id`, `reassignment_reason = $reason`
   - Resolve `new_user_id` → internal user, check delegation
   - Create new `TaskStageAssignment` for new user
   - Emit `StageAssignmentOverridden`
3. Wrapped in `DB::transaction()`

**Code snippet — overrideStageAssignment:**
```php
public function overrideStageAssignment(Task $task, TaskStageInstance $stageInstance, User $callerUser, array $assignmentOverrides, string $reason): TaskStageInstance
{
    try {
        return DB::transaction(function () use ($task, $stageInstance, $callerUser, $assignmentOverrides, $reason) {
            if (! $task->isActive()) {
                throw new TaskNotActiveException;
            }

            if ($stageInstance->status !== StageInstanceStatus::Active) {
                throw new StageNotActiveException;
            }

            if (! $this->iamPolicy->hasCapability($callerUser, 'task.override_assignment')) {
                abort(403, 'Missing task.override_assignment capability.');
            }

            foreach ($assignmentOverrides as $override) {
                $currentUser = User::where('public_id', $override['current_user_id'])->first();
                $newUser = User::where('public_id', $override['new_user_id'])->first();

                if (! $currentUser || ! $newUser) {
                    throw new AssigneeNotFoundForOverrideException;
                }

                $oldAssignment = $stageInstance->assignments()
                    ->where('user_id', $currentUser->id)
                    ->where('is_completed', false)
                    ->whereNull('reassigned_at')
                    ->first();

                if (! $oldAssignment) {
                    throw new AssigneeNotFoundForOverrideException;
                }

                // Mark old assignment as reassigned
                $oldAssignment->update([
                    'reassigned_at' => now(),
                    'reassigned_by_user_id' => $callerUser->id,
                    'reassignment_reason' => $reason,
                ]);

                // Resolve new user (check delegation)
                $effectiveUser = $this->iamPolicy->resolveAssignee($newUser);
                $delegatedFrom = $effectiveUser->id !== $newUser->id ? $newUser->id : null;
                $positionId = $newUser->currentPositionAssignment?->position_id;

                $newAssignment = TaskStageAssignment::create([
                    'task_id' => $task->id,
                    'stage_instance_id' => $stageInstance->id,
                    'sub_stage_instance_id' => null,
                    'user_id' => $effectiveUser->id,
                    'position_id' => $positionId,
                    'delegated_from_user_id' => $delegatedFrom,
                    'assignment_role' => $oldAssignment->assignment_role->value,
                    'is_completed' => false,
                    'assigned_at' => now(),
                ]);

                event(new StageAssignmentOverridden($oldAssignment, $newAssignment, $reason));
            }

            return $stageInstance->fresh(['assignments.user']);
        });
    } catch (TaskNotActiveException|StageNotActiveException|AssigneeNotFoundForOverrideException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to override stage assignment', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'stage.override_assignment',
            'entity_type' => 'task_stage_instance',
            'entity_id' => $stageInstance->id,
            'performed_by' => $callerUser->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

#### 5j. `overrideSubStageAssignment()` — Same as stage override but for sub-stage

Follows identical pattern to `overrideStageAssignment()` but operates on `TaskSubStageInstance` and sets `sub_stage_instance_id` on the new assignment.

#### 5k. Read methods — Stage history, returns, timeline

```php
public function getStageHistory(Task $task): Collection
{
    return $task->stageInstances()
        ->with([
            'blueprintStage.stageType',
            'assignments.user',
            'subStageInstances.assignments.user',
            'subStageInstances.blueprintSubStage',
        ])
        ->orderBy('created_at')
        ->get();
}

public function getStageInstance(Task $task, TaskStageInstance $stageInstance): TaskStageInstance
{
    return $stageInstance->load([
        'blueprintStage.stageType',
        'assignments.user',
        'assignments.position',
        'assignments.delegatedFromUser',
        'subStageInstances.assignments.user',
        'subStageInstances.blueprintSubStage',
    ]);
}

public function getReturnHistory(Task $task): Collection
{
    return $task->stageInstances()
        ->where('status', StageInstanceStatus::Returned->value)
        ->with(['blueprintStage', 'assignments.user'])
        ->orderBy('exited_at')
        ->get();
}

public function getTimeline(Task $task): Collection
{
    $entries = collect();

    // Stage events
    $stageInstances = $task->stageInstances()
        ->with(['blueprintStage', 'assignments.user'])
        ->get();

    foreach ($stageInstances as $si) {
        if ($si->entered_at) {
            $entries->push([
                'type' => 'stage_entered',
                'timestamp' => $si->entered_at,
                'stage_name_ar' => $si->blueprintStage?->name_ar,
                'stage_name_en' => $si->blueprintStage?->name_en,
                'status' => $si->status,
                'sequence_order' => $si->sequence_order,
            ]);
        }

        if ($si->exited_at) {
            $entries->push([
                'type' => $si->status === StageInstanceStatus::Returned ? 'stage_returned' : 'stage_completed',
                'timestamp' => $si->exited_at,
                'stage_name_ar' => $si->blueprintStage?->name_ar,
                'stage_name_en' => $si->blueprintStage?->name_en,
                'return_reason' => $si->return_reason,
                'completion_note' => $si->completion_note,
            ]);
        }

        foreach ($si->assignments as $a) {
            $entries->push([
                'type' => $a->reassigned_at ? 'assignment_overridden' : ($a->is_completed ? 'assignment_completed' : 'assignment_created'),
                'timestamp' => $a->reassigned_at ?? $a->completed_at ?? $a->assigned_at,
                'user_id' => $a->user?->public_id,
                'user_name_ar' => $a->user?->name_ar,
                'user_name_en' => $a->user?->name_en,
                'reassignment_reason' => $a->reassignment_reason,
                'completion_note' => $a->completion_note,
            ]);
        }
    }

    return $entries->sortBy('timestamp')->values();
}
```

**Test cases:**
1. `completeStage()` with `AnyAssignee` rule, 1 of 2 assignees completes → stage becomes `Completed`, next stage instance created with `Active` status
2. `returnStage()` to invalid target (no return transition defined) → throws `InvalidReturnTargetException`

**Rules applied:**
- `coding-standards.md` — Database Transactions (`DB::transaction()` on all multi-write operations)
- `coding-standards.md` — Error Handling (try/catch + `Log::channel('task')`)
- `coding-standards.md` — Enum Usage (all status comparisons use enum cases)
- `coding-standards.md` — No caching for stage instance data (write-heavy)
- `coding-standards.md` — Module Boundaries (calls `AssignmentResolutionService` directly, reads `BlueprintTransition` model — allowed cross-module read per architecture.md)

---

### 6. StageLifecycleController

**One-line summary:** Thin controller — validate via Form Request, apply ABAC visibility, delegate to `StageLifecycleService`, return API Resource. Uses `HasRateLimiting` trait. Follows `TaskController` pattern exactly.

**Key decisions:**
- ABAC visibility check on `show` / `stages` / `returns` / `timeline` endpoints via `TaskVisibilityScope`
- Mutating endpoints (`complete`, `return`, `override-assignment`) also ABAC-validate the parent task
- Stage and sub-stage instances resolved by ID within the task (not by public_id — stage instances don't have `public_id`). Route param is the stage instance internal ID. <!-- TODO: verify — if task_stage_instances gets public_id in future, switch to that -->
- Assignment override checks `task.override_assignment` capability in the service, not route middleware (since other stage lifecycle actions don't need capabilities)

**File:** `app/Modules/Task/Controllers/StageLifecycleController.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Requests\CompleteStageRequest;
use App\Modules\Task\Requests\OverrideAssignmentRequest;
use App\Modules\Task\Requests\ReturnStageRequest;
use App\Modules\Task\Requests\ReturnSubStageRequest;
use App\Modules\Task\Resources\StageReturnResource;
use App\Modules\Task\Resources\TaskStageInstanceResource;
use App\Modules\Task\Resources\TaskTimelineResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\StageLifecycleService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageLifecycleController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private StageLifecycleService $stageLifecycleService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function stages(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $stages = $this->stageLifecycleService->getStageHistory($task);

        return TaskStageInstanceResource::collection($stages);
    }

    public function showStage(Request $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $stage = $this->stageLifecycleService->getStageInstance($task, $stageInstance);

        return new TaskStageInstanceResource($stage);
    }

    public function completeStage(CompleteStageRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->completeStage(
            $task, $stageInstance, $request->user(), $request->validated('completion_note'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function returnStage(ReturnStageRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->returnStage(
            $task, $stageInstance, $request->user(),
            $request->validated('target_stage_id'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function completeSubStage(CompleteStageRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->completeSubStage(
            $task, $subStageInstance, $request->user(), $request->validated('completion_note'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function returnSubStage(ReturnSubStageRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->returnSubStage(
            $task, $subStageInstance, $request->user(),
            $request->validated('target_sub_stage_id'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function overrideStageAssignment(OverrideAssignmentRequest $request, Task $task, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->overrideStageAssignment(
            $task, $stageInstance, $request->user(),
            $request->validated('assignments'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result);
    }

    public function overrideSubStageAssignment(OverrideAssignmentRequest $request, Task $task, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $result = $this->stageLifecycleService->overrideSubStageAssignment(
            $task, $subStageInstance, $request->user(),
            $request->validated('assignments'), $request->validated('reason'),
        );

        return new TaskStageInstanceResource($result->parentStageInstance);
    }

    public function returns(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $returns = $this->stageLifecycleService->getReturnHistory($task);

        return StageReturnResource::collection($returns);
    }

    public function timeline(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
        $this->authorizeTaskVisibility($request, $task);

        $timeline = $this->stageLifecycleService->getTimeline($task);

        return TaskTimelineResource::collection($timeline);
    }

    private function authorizeTaskVisibility(Request $request, Task $task): void
    {
        $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $request->user()
        )->firstOrFail();
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin, no business logic), Rate Limiting (`HasRateLimiting` trait, `RateLimits::MUTATE` for mutations, `RateLimits::LIST` for reads).

---

### 7. API Resources

**One-line summary:** Two new resources for return history and timeline. Existing `TaskStageInstanceResource` and `TaskStageAssignmentResource` are reused for stage history and detail endpoints.

#### StageReturnResource
```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StageReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'blueprint_stage' => [
                'public_id' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->public_id),
                'name_ar' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_ar),
                'name_en' => $this->whenLoaded('blueprintStage', fn () => $this->blueprintStage->name_en),
            ],
            'sequence_order' => $this->sequence_order,
            'return_reason' => $this->return_reason,
            'exited_at' => $this->exited_at?->toIso8601String(),
            'returned_by' => $this->whenLoaded('assignments', function () {
                $returner = $this->assignments->first();
                return $returner ? [
                    'user_id' => $returner->user?->public_id,
                    'user_name_ar' => $returner->user?->name_ar,
                    'user_name_en' => $returner->user?->name_en,
                ] : null;
            }),
        ];
    }
}
```

#### TaskTimelineResource
```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'type' => $data['type'],
            'timestamp' => $data['timestamp']?->toIso8601String(),
            'stage_name_ar' => $data['stage_name_ar'] ?? null,
            'stage_name_en' => $data['stage_name_en'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'user_name_ar' => $data['user_name_ar'] ?? null,
            'user_name_en' => $data['user_name_en'] ?? null,
            'return_reason' => $data['return_reason'] ?? null,
            'reassignment_reason' => $data['reassignment_reason'] ?? null,
            'completion_note' => $data['completion_note'] ?? null,
            'status' => $data['status'] ?? null,
            'sequence_order' => $data['sequence_order'] ?? null,
        ];
    }
}
```

**Update `TaskStageAssignmentResource`** to include the new `completion_note` field:
```php
// Add to the toArray return:
'completion_note' => $this->completion_note,
'reassigned_at' => $this->reassigned_at?->toIso8601String(),
'reassigned_by_user_id' => $this->reassignedByUser?->public_id,
'reassignment_reason' => $this->reassignment_reason,
```

**Rules:** `coding-standards.md` — API Resources (`public_id` only, never internal `id`).

---

### 8. Routes

**One-line summary:** Append stage lifecycle routes to existing `routes/api/v1/tasks.php`. All routes under `auth:sanctum` middleware. No capability middleware on routes — ABAC handled in service/controller.

**Code snippet — additions to `routes/api/v1/tasks.php`:**
```php
use App\Modules\Task\Controllers\StageLifecycleController;

// Inside the existing Route::middleware(['auth:sanctum'])->prefix('tasks')->group(function () { ... })
// Add after the existing lifecycle routes:

// Stage Lifecycle
Route::get('{task}/stages', [StageLifecycleController::class, 'stages']);
Route::get('{task}/stages/{stageInstance}', [StageLifecycleController::class, 'showStage']);
Route::post('{task}/stages/{stageInstance}/complete', [StageLifecycleController::class, 'completeStage']);
Route::post('{task}/stages/{stageInstance}/return', [StageLifecycleController::class, 'returnStage']);
Route::post('{task}/stages/{stageInstance}/override-assignment', [StageLifecycleController::class, 'overrideStageAssignment']);

// Sub-stage Lifecycle
Route::post('{task}/sub-stages/{subStageInstance}/complete', [StageLifecycleController::class, 'completeSubStage']);
Route::post('{task}/sub-stages/{subStageInstance}/return', [StageLifecycleController::class, 'returnSubStage']);
Route::post('{task}/sub-stages/{subStageInstance}/override-assignment', [StageLifecycleController::class, 'overrideSubStageAssignment']);

// History & Timeline
Route::get('{task}/returns', [StageLifecycleController::class, 'returns']);
Route::get('{task}/timeline', [StageLifecycleController::class, 'timeline']);
```

**Route model binding note:** `{task}` resolves via `public_id` (existing TenantModel behavior). `{stageInstance}` and `{subStageInstance}` resolve by internal `id` since `TaskStageInstance` and `TaskSubStageInstance` don't have `public_id`. <!-- TODO: verify — consider adding public_id to stage instances if FE needs stable URLs -->

**Rules:** `coding-standards.md` — Routes (kebab-case). Rate limiting applied in controller, not routes.

---

### 9. Tests

**One-line summary:** Five Pest feature test files covering stage complete (all 3 completion rules + final-stage task completion), stage return (valid and invalid), sub-stage complete/return, assignment override (with and without capability), and stage history/timeline endpoints. Follow the `TaskLifecycleTest.php` pattern exactly.

**Key decisions:**
- Each test file provisions its own tenant, seeds capabilities, creates a Blueprint with stages and transitions
- Helper `beforeEach` sets up: tenant, user, Blueprint with 3 stages (with transitions), task launched at Stage 1
- Test multiple completion rules via separate test cases, not datasets (keeps tests readable)

#### StageCompleteTest — Key test cases

```php
it('completes stage with AnyAssignee rule and advances to next stage', function () {
    // Setup: task at Stage 1 (AnyAssignee, 2 assignees)
    // Action: POST /v1/tasks/{task}/stages/{stageInstance}/complete
    // Assert: stage 1 status=Completed, stage 2 created with Active, 200 OK
});

it('completes stage with AllAssignees rule only when all required assignees complete', function () {
    // Setup: task at Stage 1 (AllAssignees, 2 required assignees)
    // Action: first assignee completes → stage still Active
    // Action: second assignee completes → stage Completed, advances to Stage 2
});

it('completes stage with LeadAssignee rule when lead completes', function () {
    // Setup: task at Stage 1 (LeadAssignee, 1 lead + 1 required)
    // Action: lead completes → stage Completed regardless of other assignee
});

it('completes the task when final stage is completed', function () {
    // Setup: task at final stage (Stage 3, no advance transition)
    // Action: complete → task status=Completed, completed_at set
});

it('rejects stage completion when required sub-stages are incomplete', function () {
    // Setup: task at Stage 1 with 2 required sub-stages, only 1 completed
    // Action: POST complete → 422 RequiredSubStagesIncompleteException
});

it('rejects stage completion when user is not an assignee', function () {
    // Setup: task at Stage 1, request from non-assignee user
    // Action: POST complete → 422 UserNotAssigneeException
});

it('rejects stage completion when task is not active', function () {
    // Setup: task with status=Suspended
    // Action: POST complete → 422 TaskNotActiveException
});
```

#### StageReturnTest — Key test cases

```php
it('returns stage to valid target and creates new stage instance', function () {
    // Setup: task at Stage 2 with return transition to Stage 1
    // Action: POST /v1/tasks/{task}/stages/{stage2Instance}/return
    // Assert: stage 2 status=Returned, new Stage 1 instance created with Active
});

it('rejects return to invalid target with no transition defined', function () {
    // Setup: task at Stage 2, no return transition to Stage 3
    // Action: POST return with target_stage_id = Stage 3 → 422
});

it('cancels active sub-stages when stage is returned', function () {
    // Setup: task at Stage 2 with active sub-stages
    // Action: return to Stage 1
    // Assert: all sub-stages of Stage 2 status=Returned
});
```

#### AssignmentOverrideTest — Key test cases

```php
it('overrides stage assignment with task.override_assignment capability', function () {
    // Setup: task at Stage 1, caller has capability
    // Action: POST override-assignment
    // Assert: old assignment has reassigned_at, new assignment created, 200 OK
});

it('rejects override without task.override_assignment capability', function () {
    // Setup: task at Stage 1, caller lacks capability
    // Action: POST override-assignment → 403
});
```

**Rules:** `testing-policy.md` — Feature tests mandatory, use factories, test happy path + auth + validation.

---

## Execution Order

1. **Migration** — Create `add_completion_note_to_task_stage_assignments_table` migration
2. **Model update** — Add `completion_note` to `TaskStageAssignment` `#[Fillable]`
3. **Exceptions** — Create all 7 exception classes
4. **Events** — Create all 9 event classes
5. **Form Requests** — Create all 4 Form Request classes
6. **Resources** — Create `StageReturnResource`, `TaskTimelineResource`; update `TaskStageAssignmentResource` to include new fields
7. **Service** — Create `StageLifecycleService` (depends on: models, enums, events, exceptions, `AssignmentResolutionService`)
8. **Controller** — Create `StageLifecycleController` (depends on: service, requests, resources)
9. **Routes** — Add stage lifecycle routes to `routes/api/v1/tasks.php`
10. **Tests** — Create all 5 test files
11. **Run tests** — `php artisan test --compact`
12. **Run Pint** — `vendor/bin/pint --dirty --format agent`

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Rate Limit | Description |
|--------|----------|------|------------|------------|-------------|
| POST | `/api/v1/tasks/{task}/stages/{stageInstance}/complete` | Sanctum | — (assignee only) | MUTATE | Complete assignment on active stage |
| POST | `/api/v1/tasks/{task}/stages/{stageInstance}/return` | Sanctum | — (assignee only) | MUTATE | Return to earlier stage |
| POST | `/api/v1/tasks/{task}/stages/{stageInstance}/override-assignment` | Sanctum | `task.override_assignment` | MUTATE | Reassign stage assignees |
| POST | `/api/v1/tasks/{task}/sub-stages/{subStageInstance}/complete` | Sanctum | — (assignee only) | MUTATE | Complete assignment on active sub-stage |
| POST | `/api/v1/tasks/{task}/sub-stages/{subStageInstance}/return` | Sanctum | — (assignee only) | MUTATE | Return to earlier sub-stage |
| POST | `/api/v1/tasks/{task}/sub-stages/{subStageInstance}/override-assignment` | Sanctum | `task.override_assignment` | MUTATE | Reassign sub-stage assignees |
| GET | `/api/v1/tasks/{task}/stages` | Sanctum | — (ABAC filtered) | LIST | List all stage instances (full list) |
| GET | `/api/v1/tasks/{task}/stages/{stageInstance}` | Sanctum | — (ABAC filtered) | LIST | Show stage instance detail |
| GET | `/api/v1/tasks/{task}/returns` | Sanctum | — (ABAC filtered) | LIST | List returned stage instances |
| GET | `/api/v1/tasks/{task}/timeline` | Sanctum | — (ABAC filtered) | LIST | Chronological task timeline |

**Pagination:** All endpoints return **full list** (no cursor pagination). Stage instances, assignments, and timeline entries per task are bounded (< 200 total). See spec NFR — Pagination.

**Response envelope:** All responses use API Resources wrapped in Laravel's default `{ "data": [...] }` structure.

**Error responses:**
| Status | Exception | When |
|--------|-----------|------|
| 422 | `TaskNotActiveException` | Task is not active |
| 422 | `StageNotActiveException` | Stage instance is not active |
| 422 | `SubStageNotActiveException` | Sub-stage instance is not active |
| 422 | `UserNotAssigneeException` | User not assigned to stage |
| 422 | `InvalidReturnTargetException` | No valid return transition |
| 422 | `RequiredSubStagesIncompleteException` | Required sub-stages not done |
| 422 | `InvalidSubStageReturnTargetException` | Invalid sub-stage return target |
| 422 | `AssigneeNotFoundForOverrideException` | User not an active assignee for override |
| 403 | `abort()` | Missing `task.override_assignment` capability |

---

## What to Test Manually

1. **Happy path — full stage progression:** Create a Blueprint with 3 stages and advance transitions → launch task → complete Stage 1 → verify Stage 2 auto-created as Active → complete Stage 2 → verify Stage 3 → complete Stage 3 → verify task `status = completed`, `completed_at` set.
2. **Completion rules — AnyAssignee:** Create stage with 2 required assignees and `AnyAssignee` rule → first assignee completes → verify stage advances immediately (second assignee need not complete).
3. **Completion rules — AllAssignees:** Create stage with 2 required assignees and `AllAssignees` rule → first assignee completes → verify stage stays `Active` → second completes → verify stage advances.
4. **Completion rules — LeadAssignee:** Create stage with 1 lead + 1 required assignee → required completes → stage stays `Active` → lead completes → stage advances.
5. **Sub-stage completion:** Blueprint stage with 2 sequential sub-stages → complete sub-stage 1 → verify sub-stage 2 activated → complete sub-stage 2 → verify parent stage can now complete.
6. **Sub-stage blocks stage:** Stage has required sub-stages, try completing stage before sub-stages → verify 422 error.
7. **Stage return — valid:** Task at Stage 2 with return transition to Stage 1 → return with reason → verify Stage 2 `Returned`, new Stage 1 instance created, fresh assignments resolved.
8. **Stage return — invalid target:** Try returning to a stage not in transitions → verify 422 error.
9. **Stage return — sub-stages cancelled:** Return a stage that has active sub-stages → verify all sub-stages marked `Returned`.
10. **Return creates new instance:** Return to Stage 1 → verify a NEW `task_stage_instance` row is created (original Stage 1 instance preserved with `Completed` status).
11. **Assignment override — with capability:** User with `task.override_assignment` overrides assignee → verify old assignment has `reassigned_at`/`reassignment_reason`, new assignment created.
12. **Assignment override — without capability:** User without capability attempts override → verify 403.
13. **Delegation on advance:** Stage 2 assignee has active delegation → advance to Stage 2 → verify assignment goes to delegate with `delegated_from_user_id`.
14. **Manual-at-launch re-entry:** Blueprint with `manual_at_launch` stage → launch with manual users → return to that stage → verify same users re-assigned.
15. **Stage history endpoint:** After 3 stage progressions → `GET /tasks/{task}/stages` → verify all instances listed in order.
16. **Return history endpoint:** After 2 returns → `GET /tasks/{task}/returns` → verify only returned instances shown.
17. **Timeline endpoint:** After full lifecycle → `GET /tasks/{task}/timeline` → verify chronological events include entries, exits, assignments, overrides.
18. **ABAC on read endpoints:** User without visibility to task → `GET /tasks/{task}/stages` → verify 404 (not 403).
19. **Rate limiting:** Hit complete endpoint 31 times in 1 minute → verify 429 on 31st request.
20. **Concurrent completion (AllAssignees):** Two assignees complete simultaneously → verify only one triggers stage advance (DB transaction prevents double-advance).
