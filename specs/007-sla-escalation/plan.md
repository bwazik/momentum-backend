# Plan: SLA Escalation

> **Spec:** 007-sla-escalation
> **Date:** 2026-06-13
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| `sla_timer_instances.public_id` — add despite ERD omission? | **Yes — add `public_id` (UUID v7).** | API rules require `public_id` for every exposed resource. Timer instances appear in list/show APIs. |
| Working calendar selection — initiator dept, stage dept, or tenant default? | **Use stage/sub-stage `owning_department_id` calendar when available; fall back to tenant default `WorkingCalendar`.** | Stage owning department is the most relevant context. The department's working calendar (looked up via `departments.working_calendar_id` if it exists, else tenant default `is_default = true`) determines working hours/days for SLA deadlines. <!-- TODO: verify departments.working_calendar_id column exists; if not, always use tenant default --> |
| Explicit manual escalation target — allow or force auto? | **Allow optional explicit `escalated_to_position_id` in the manual escalation request.** Target is resolved automatically if omitted. | Gives follow-up specialists flexibility while maintaining a sensible default. |
| New capabilities — `task.escalate` and `task.resolve_escalations`? | **Yes — add both to `CapabilitySeeder`.** Scoped by monitoring scope / department scope. | Required for ABAC enforcement on manual escalation and resolution endpoints. |
| Timer completion on stage return — `Completed` vs distinct status? | **Use `Completed` for MVP.** | Avoids introducing a 6th enum case. If analytics later needs the distinction, add a `completed_reason` column or a `Cancelled` case in V2. |

---

## Technical Approach

Build the **Tracking** module under `app/Modules/Tracking/` — the first module in this namespace. Two tables (`sla_timer_instances`, `escalations`), 3 enums, 2 models, 3 services, 2 controllers, event listeners for 8 Task events, a scheduled job for threshold scanning, 8 new domain events, 7 domain exceptions, and full feature tests. The module observes Task lifecycle events via Laravel event listeners and **never writes** Task tables.

**Key decisions:**
- **New module directory `app/Modules/Tracking/`** — clean separation per `architecture.md`. The Tracking module owns `sla_timer_instances` and `escalations` tables exclusively.
- **Event listeners** — one listener class per consumed Task event (e.g., `HandleStageInstanceCreated`). Registered in `EventServiceProvider` or via `Event::listen()` in a module service provider. Listeners handle timer creation/completion synchronously since the originating Task event already fired after commit.
- **`WorkingDayCalculator` reuse** — cross-module service call to `App\Modules\Organization\Services\WorkingDayCalculator` for working-time-aware deadline calculation. Extend with `addWorkingHours()` and `workingSecondsBetween()` methods.
- **Scheduled SLA check via `CheckSlaTimersCommand`** — Artisan command in the Laravel scheduler (`schedule:run`) that queries due timers per tenant and dispatches `CheckSlaTimersJob` per tenant. The job uses `WHERE status IN (Running, Warning) AND (warning_at <= now() OR deadline_at <= now())` with `lockForUpdate()` to prevent double-processing.
- **Escalation target resolution as a dedicated service method** — `SlaEscalationService::resolveEscalationTarget()` checks Blueprint `escalation_position_id` first, then falls back to assignee's position `reports_to_position_id → currentOccupant`.
- **No caching for timer or escalation lists** — per spec NFR, these are time-sensitive and cursor-paginated.
- **Working calendar lookup** — use stage `owning_department_id` → department's working calendar, or tenant default. Cache holidays at cold tier (3600s).

---

## Affected Modules / Files

### New Files (to create)

| File | Purpose |
|------|---------|
| **Enums** | |
| `app/Modules/Tracking/Enums/SlaTimerStatus.php` | Running(1), Warning(2), Breached(3), Completed(4), Paused(5) |
| `app/Modules/Tracking/Enums/EscalationType.php` | AutoSlaBreach(1), Manual(2) |
| `app/Modules/Tracking/Enums/EscalationStatus.php` | Open(1), Resolved(2) |
| **Migrations** | |
| `database/migrations/tenant/2026_06_13_000001_create_sla_timer_instances_table.php` | SLA timer instances |
| `database/migrations/tenant/2026_06_13_000002_create_escalations_table.php` | Escalation records |
| **Models** | |
| `app/Modules/Tracking/Models/SlaTimerInstance.php` | SLA timer entity |
| `app/Modules/Tracking/Models/Escalation.php` | Escalation entity |
| **Services** | |
| `app/Modules/Tracking/Services/SlaTimerService.php` | Timer CRUD, pause, resume, complete, deadline calculation |
| `app/Modules/Tracking/Services/SlaEscalationService.php` | Manual escalation, resolution, auto-escalation, target resolution |
| `app/Modules/Tracking/Services/SlaThresholdService.php` | Warning/breach detection logic used by scheduled job |
| **Controllers** | |
| `app/Modules/Tracking/Controllers/SlaTimerController.php` | Timer health + list APIs |
| `app/Modules/Tracking/Controllers/EscalationController.php` | Escalation CRUD APIs |
| **Requests** | |
| `app/Modules/Tracking/Requests/ListSlaTimersRequest.php` | Timer list filter validation |
| `app/Modules/Tracking/Requests/ListEscalationsRequest.php` | Escalation list filter validation |
| `app/Modules/Tracking/Requests/CreateManualEscalationRequest.php` | Manual escalation creation validation |
| `app/Modules/Tracking/Requests/ResolveEscalationRequest.php` | Escalation resolution validation |
| **Resources** | |
| `app/Modules/Tracking/Resources/SlaTimerInstanceResource.php` | Timer JSON shape |
| `app/Modules/Tracking/Resources/TaskSlaHealthResource.php` | Task-level SLA health JSON shape |
| `app/Modules/Tracking/Resources/EscalationResource.php` | Escalation JSON shape |
| `app/Modules/Tracking/Resources/EscalationDetailResource.php` | Escalation detail JSON shape |
| **Events** | |
| `app/Modules/Tracking/Events/SlaTimerStarted.php` | Timer started |
| `app/Modules/Tracking/Events/SlaTimerPaused.php` | Timer paused |
| `app/Modules/Tracking/Events/SlaTimerResumed.php` | Timer resumed |
| `app/Modules/Tracking/Events/SlaTimerCompleted.php` | Timer completed |
| `app/Modules/Tracking/Events/SlaWarningTriggered.php` | Warning threshold reached |
| `app/Modules/Tracking/Events/SlaBreached.php` | Deadline breached |
| `app/Modules/Tracking/Events/EscalationCreated.php` | Escalation created |
| `app/Modules/Tracking/Events/EscalationResolved.php` | Escalation resolved |
| **Listeners** | |
| `app/Modules/Tracking/Listeners/HandleStageInstanceCreated.php` | Create timer on stage entry |
| `app/Modules/Tracking/Listeners/HandleSubStageInstanceCreated.php` | Create timer on sub-stage entry |
| `app/Modules/Tracking/Listeners/HandleStageInstanceCompleted.php` | Complete stage timer |
| `app/Modules/Tracking/Listeners/HandleSubStageInstanceCompleted.php` | Complete sub-stage timer |
| `app/Modules/Tracking/Listeners/HandleStageInstanceReturned.php` | Complete returned stage + sub-stage timers |
| `app/Modules/Tracking/Listeners/HandleTaskSuspended.php` | Pause all task timers |
| `app/Modules/Tracking/Listeners/HandleTaskResumed.php` | Resume all task timers |
| `app/Modules/Tracking/Listeners/HandleTaskCompletedOrCancelled.php` | Complete all task timers |
| **Exceptions** | |
| `app/Modules/Tracking/Exceptions/SlaPolicyMissingException.php` | 422 — SLA policy not found |
| `app/Modules/Tracking/Exceptions/SlaTimerAlreadyExistsException.php` | 422 — duplicate active timer |
| `app/Modules/Tracking/Exceptions/SlaTimerNotActiveException.php` | 422 — timer not in actionable state |
| `app/Modules/Tracking/Exceptions/EscalationTargetNotFoundException.php` | 422 — no escalation target |
| `app/Modules/Tracking/Exceptions/DuplicateOpenEscalationException.php` | 422 — duplicate open escalation |
| `app/Modules/Tracking/Exceptions/EscalationAlreadyResolvedException.php` | 422 — already resolved |
| `app/Modules/Tracking/Exceptions/EscalationResolutionUnauthorizedException.php` | 403 — not authorized to resolve |
| **Jobs** | |
| `app/Modules/Tracking/Jobs/CheckSlaTimersJob.php` | Tenant-scoped SLA threshold scan |
| **Commands** | |
| `app/Modules/Tracking/Commands/CheckSlaTimersCommand.php` | Artisan command dispatching per-tenant jobs |
| **Providers** | |
| **Factories** | |
| `database/factories/SlaTimerInstanceFactory.php` | Timer factory |
| `database/factories/EscalationFactory.php` | Escalation factory |
| **Routes** | |
| `routes/api/v1/tracking.php` | Tracking module routes |
| **Tests** | |
| `tests/Feature/Modules/Tracking/SlaTimerCreationTest.php` | Timer creation from events |
| `tests/Feature/Modules/Tracking/SlaTimerLifecycleTest.php` | Pause, resume, complete timers |
| `tests/Feature/Modules/Tracking/SlaThresholdDetectionTest.php` | Warning and breach detection |
| `tests/Feature/Modules/Tracking/AutoEscalationTest.php` | Automatic escalation on breach |
| `tests/Feature/Modules/Tracking/ManualEscalationTest.php` | Manual escalation + resolution |
| `tests/Feature/Modules/Tracking/SlaTimerApiTest.php` | Timer health + list API endpoints |
| `tests/Feature/Modules/Tracking/EscalationApiTest.php` | Escalation list/show/create/resolve APIs |

### Modified Files (to edit)

| File | Change |
|------|--------|
| `config/logging.php` | Add `tracking` logging channel |
| `database/seeders/CapabilitySeeder.php` | Add `task.escalate` and `task.resolve_escalations` capabilities |
| `routes/api.php` | Include `tracking.php` route file |
| `app/Console/Kernel.php` (or `routes/console.php`) | Schedule `CheckSlaTimersCommand` |
| `app/Modules/Organization/Services/WorkingDayCalculator.php` | Add `addWorkingHours()` and `workingSecondsBetween()` methods |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create 3 enums in `app/Modules/Tracking/Enums/`. Reuse `SlaUnit` from `app/Modules/Blueprint/Enums/SlaUnit.php`.

**Key decisions:**
- `SlaTimerStatus` has 5 cases: `Running`, `Warning`, `Breached`, `Completed`, `Paused`
- `EscalationStatus` has only 2 cases (MVP — no `Rejected`, `Dismissed`)
- All store TINYINT, cast in model `casts()` method

**Files:**
- `app/Modules/Tracking/Enums/SlaTimerStatus.php`
- `app/Modules/Tracking/Enums/EscalationType.php`
- `app/Modules/Tracking/Enums/EscalationStatus.php`

**Code snippet — SlaTimerStatus:**
```php
<?php

namespace App\Modules\Tracking\Enums;

enum SlaTimerStatus: int
{
    case Running = 1;
    case Warning = 2;
    case Breached = 3;
    case Completed = 4;
    case Paused = 5;

    public function isTerminal(): bool
    {
        return in_array($this, [self::Breached, self::Completed], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::Warning], true);
    }
}
```

**Code snippet — EscalationType:**
```php
<?php

namespace App\Modules\Tracking\Enums;

enum EscalationType: int
{
    case AutoSlaBreach = 1;
    case Manual = 2;
}
```

**Code snippet — EscalationStatus:**
```php
<?php

namespace App\Modules\Tracking\Enums;

enum EscalationStatus: int
{
    case Open = 1;
    case Resolved = 2;
}
```

**Test cases:**
1. `SlaTimerStatus::Running->isActive()` → `true`
2. `SlaTimerStatus::Completed->isTerminal()` → `true`

**Rules:** `coding-standards.md` — Enum Usage. `Rule::enum()` in Form Requests. No magic numbers.

---

### 2. Migrations

**One-line summary:** Create 2 migrations in `database/migrations/tenant/`. Both use `bigIncrements`, `public_id` (UUID v7), FKs, and indexes.

**Key decisions:**
- `sla_timer_instances`: FK to `tasks`, nullable FKs to `task_stage_instances` and `task_sub_stage_instances`, FK to `sla_policies` and `working_calendars`. Composite index on `(task_id, status)` for pause/resume queries. Index on `(status, warning_at)` and `(status, deadline_at)` for scheduled scans.
- `escalations`: FK to `tasks`, nullable FKs to `task_stage_instances`, `task_sub_stage_instances`, and `sla_timer_instances`. FK to `users` and `positions`. Index on `(task_id, status)`.
- No soft deletes on either table — SLA/escalation records are historical and never deleted.
- No `tenant_id` columns.

**File:** `database/migrations/tenant/2026_06_13_000001_create_sla_timer_instances_table.php`

**Code snippet:**
```php
Schema::create('sla_timer_instances', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
    $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
    $table->foreignId('sla_policy_id')->constrained('sla_policies');
    $table->foreignId('working_calendar_id')->constrained('working_calendars');
    $table->timestamp('started_at');
    $table->timestamp('deadline_at');
    $table->timestamp('warning_at')->nullable();
    $table->timestamp('paused_at')->nullable();
    $table->unsignedInteger('elapsed_before_pause')->default(0);
    $table->timestamp('completed_at')->nullable();
    $table->unsignedTinyInteger('status')->default(1); // Running
    $table->timestamps();

    $table->index(['task_id', 'status']);
    $table->index(['status', 'warning_at']);
    $table->index(['status', 'deadline_at']);
    $table->index('stage_instance_id');
    $table->index('sub_stage_instance_id');
});
```

**File:** `database/migrations/tenant/2026_06_13_000002_create_escalations_table.php`

**Code snippet:**
```php
Schema::create('escalations', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('stage_instance_id')->nullable()->constrained('task_stage_instances')->cascadeOnDelete();
    $table->foreignId('sub_stage_instance_id')->nullable()->constrained('task_sub_stage_instances')->cascadeOnDelete();
    $table->foreignId('sla_timer_instance_id')->nullable()->constrained('sla_timer_instances')->nullOnDelete();
    $table->unsignedTinyInteger('escalation_type');
    $table->foreignId('escalated_to_user_id')->constrained('users');
    $table->foreignId('escalated_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
    $table->foreignId('escalated_by_user_id')->nullable()->constrained('users');
    $table->text('reason');
    $table->unsignedTinyInteger('status')->default(1); // Open
    $table->text('resolution_note')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();

    $table->index(['task_id', 'status']);
    $table->index(['escalated_to_user_id', 'status']);
    $table->index('escalation_type');
});
```

**Test cases:**
1. `SlaTimerInstance::create([...])` with `stage_instance_id` set, `sub_stage_instance_id` null → valid row
2. `Escalation::create([...])` with `escalation_type = EscalationType::AutoSlaBreach->value` → correct enum storage

**Rules:** `coding-standards.md` — Migrations. No `tenant_id`. Use `constrained()`. Proper indexes.

---

### 3. Models

**One-line summary:** `SlaTimerInstance` extends `TenantModel` (has `HasPublicId`); `Escalation` extends `TenantModel`. Both define casts, relationships, and scopes.

**Key decisions:**
- Both models use `TenantModel` which provides `HasPublicId` for `public_id` route binding
- No `SoftDeletes` — these are historical records
- `SlaTimerInstance` has `scopeActive()` for `status IN (Running, Warning)`
- `SlaTimerInstance` has `scopeForTask($taskId)` and `scopeDue()`
- `Escalation` has `scopeOpen()` for `status = Open`

**File:** `app/Modules/Tracking/Models/SlaTimerInstance.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\SlaTimerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'sla_policy_id',
    'working_calendar_id', 'started_at', 'deadline_at', 'warning_at',
    'paused_at', 'elapsed_before_pause', 'completed_at', 'status',
])]
class SlaTimerInstance extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SlaTimerStatus::class,
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'warning_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'elapsed_before_pause' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function stageInstance(): BelongsTo
    {
        return $this->belongsTo(TaskStageInstance::class, 'stage_instance_id');
    }

    public function subStageInstance(): BelongsTo
    {
        return $this->belongsTo(TaskSubStageInstance::class, 'sub_stage_instance_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    public function workingCalendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class, 'sla_timer_instance_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            SlaTimerStatus::Running->value,
            SlaTimerStatus::Warning->value,
        ]);
    }

    public function scopeForTask($query, int $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeDueWarning($query)
    {
        return $query->where('status', SlaTimerStatus::Running->value)
            ->whereNotNull('warning_at')
            ->where('warning_at', '<=', now());
    }

    public function scopeDueBreach($query)
    {
        return $query->whereIn('status', [
            SlaTimerStatus::Running->value,
            SlaTimerStatus::Warning->value,
        ])->where('deadline_at', '<=', now());
    }
}
```

**File:** `app/Modules/Tracking/Models/Escalation.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Tracking\Enums\EscalationStatus;
use App\Modules\Tracking\Enums\EscalationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'task_id', 'stage_instance_id', 'sub_stage_instance_id', 'sla_timer_instance_id',
    'escalation_type', 'escalated_to_user_id', 'escalated_to_position_id',
    'escalated_by_user_id', 'reason', 'status', 'resolution_note', 'resolved_at',
])]
class Escalation extends TenantModel
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'escalation_type' => EscalationType::class,
            'status' => EscalationStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function stageInstance(): BelongsTo { return $this->belongsTo(TaskStageInstance::class, 'stage_instance_id'); }
    public function subStageInstance(): BelongsTo { return $this->belongsTo(TaskSubStageInstance::class, 'sub_stage_instance_id'); }
    public function slaTimerInstance(): BelongsTo { return $this->belongsTo(SlaTimerInstance::class, 'sla_timer_instance_id'); }
    public function escalatedToUser(): BelongsTo { return $this->belongsTo(User::class, 'escalated_to_user_id'); }
    public function escalatedToPosition(): BelongsTo { return $this->belongsTo(Position::class, 'escalated_to_position_id'); }
    public function escalatedByUser(): BelongsTo { return $this->belongsTo(User::class, 'escalated_by_user_id'); }

    public function scopeOpen($query)
    {
        return $query->where('status', EscalationStatus::Open->value);
    }
}
```

**Rules:** `coding-standards.md` — Models. No `tenant_id`. Use `casts()`. `#[Fillable]` attribute.

---

### 4. Exceptions

**One-line summary:** Seven new domain exceptions extending `App\Exceptions\DomainException`. Automatically rendered by the existing `DomainException` renderable handler.

**Key decisions:**
- All extend `DomainException` (HTTP 422 default)
- `EscalationResolutionUnauthorizedException` uses `statusCode = 403`
- No changes to `bootstrap/app.php` needed (base `DomainException` handler catches all subclasses)

**Exception messages:**

| Exception | Message | Status |
|-----------|---------|--------|
| `SlaPolicyMissingException` | `SLA policy not found for stage/sub-stage.` | 422 |
| `SlaTimerAlreadyExistsException` | `An active SLA timer already exists for this stage instance.` | 422 |
| `SlaTimerNotActiveException` | `SLA timer is not in an actionable state.` | 422 |
| `EscalationTargetNotFoundException` | `No escalation target could be resolved for this stage.` | 422 |
| `DuplicateOpenEscalationException` | `An open escalation already exists for this stage from this user.` | 422 |
| `EscalationAlreadyResolvedException` | `This escalation has already been resolved.` | 422 |
| `EscalationResolutionUnauthorizedException` | `You are not authorized to resolve this escalation.` | 403 |

**Code snippet — pattern:**
```php
<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class DuplicateOpenEscalationException extends DomainException
{
    public function __construct()
    {
        parent::__construct('An open escalation already exists for this stage from this user.');
    }
}
```

**Code snippet — 403 pattern:**
```php
<?php

namespace App\Modules\Tracking\Exceptions;

use App\Exceptions\DomainException;

class EscalationResolutionUnauthorizedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct('You are not authorized to resolve this escalation.');
    }
}
```

**Rules:** `coding-standards.md` — Error Handling.

---

### 5. Events

**One-line summary:** Eight new events implementing `ShouldDispatchAfterCommit`. Follow existing `StageInstanceCreated` pattern.

**Files:**
- `SlaTimerStarted(SlaTimerInstance $timer)`
- `SlaTimerPaused(SlaTimerInstance $timer)`
- `SlaTimerResumed(SlaTimerInstance $timer)`
- `SlaTimerCompleted(SlaTimerInstance $timer)`
- `SlaWarningTriggered(SlaTimerInstance $timer)`
- `SlaBreached(SlaTimerInstance $timer)`
- `EscalationCreated(Escalation $escalation)`
- `EscalationResolved(Escalation $escalation)`

**Code snippet — SlaBreached:**
```php
<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Tracking\Models\SlaTimerInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SlaBreached implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public SlaTimerInstance $timer) {}
}
```

**Code snippet — EscalationCreated:**
```php
<?php

namespace App\Modules\Tracking\Events;

use App\Modules\Tracking\Models\Escalation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EscalationCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Escalation $escalation) {}
}
```

**Rules:** `coding-standards.md` — Domain Events (`ShouldDispatchAfterCommit` is non-negotiable).

---

### 6. Listeners — Consuming Task Events

**One-line summary:** Eight listener classes in `app/Modules/Tracking/Listeners/`. Each listens to a Task module event and delegates to `SlaTimerService`. All use try/catch with `Log::channel('tracking')`.

**Key decisions:**
- Listeners are auto-discovered by Laravel's event discovery via `->withEvents(discover: [__DIR__.'/../app/Modules/*/Listeners'])` in `bootstrap/app.php`. No manual registration needed.
- All listeners are synchronous (the originating event already fired after commit).
- Idempotent: `HandleStageInstanceCreated` checks `SlaTimerInstance::where('stage_instance_id', $id)->active()->exists()` before creating.
- `HandleTaskCompletedOrCancelled` listens to both `TaskCompleted` and `TaskCancelled`.

**Listener → Event mapping:**

| Listener | Task Event(s) | Action |
|----------|--------------|--------|
| `HandleStageInstanceCreated` | `StageInstanceCreated` | Create timer if stage has `sla_policy_id` |
| `HandleSubStageInstanceCreated` | `SubStageInstanceCreated` | Create timer if sub-stage has `sla_policy_id` |
| `HandleStageInstanceCompleted` | `StageInstanceCompleted` | Mark stage timer `Completed` |
| `HandleSubStageInstanceCompleted` | `SubStageInstanceCompleted` | Mark sub-stage timer `Completed` |
| `HandleStageInstanceReturned` | `StageInstanceReturned` | Mark returned stage + sub-stage timers `Completed` |
| `HandleTaskSuspended` | `TaskSuspended` | Pause all active task timers |
| `HandleTaskResumed` | `TaskResumed` | Resume all paused task timers |
| `HandleTaskCompletedOrCancelled` | `TaskCompleted`, `TaskCancelled` | Complete all non-terminal task timers |

**Code snippet — HandleStageInstanceCreated:**
```php
<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\StageInstanceCreated;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleStageInstanceCreated
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(StageInstanceCreated $event): void
    {
        try {
            $stageInstance = $event->stageInstance;
            $blueprintStage = $stageInstance->blueprintStage;

            if (! $blueprintStage->sla_policy_id) {
                return; // No SLA policy → no timer
            }

            $this->slaTimerService->createTimerForStage($stageInstance);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to handle StageInstanceCreated', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.create_from_event',
                'entity_type' => 'task_stage_instance',
                'entity_id' => $event->stageInstance->id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            // Do not re-throw — listener failure should not roll back Task operations
        }
    }
}
```

**Code snippet — HandleTaskSuspended:**
```php
<?php

namespace App\Modules\Tracking\Listeners;

use App\Modules\Task\Events\TaskSuspended;
use App\Modules\Tracking\Services\SlaTimerService;
use Illuminate\Support\Facades\Log;

class HandleTaskSuspended
{
    public function __construct(private SlaTimerService $slaTimerService) {}

    public function handle(TaskSuspended $event): void
    {
        try {
            $this->slaTimerService->pauseAllTimersForTask($event->task);
        } catch (\Throwable $e) {
            Log::channel('tracking')->error('Failed to pause timers on task suspension', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'sla_timer.pause_all',
                'entity_type' => 'task',
                'entity_id' => $event->task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

**Test cases:**
1. Dispatch `StageInstanceCreated` for a stage with `sla_policy_id` → `sla_timer_instances` count = 1, status = `Running`
2. Dispatch `StageInstanceCreated` for a stage without `sla_policy_id` → `sla_timer_instances` count = 0

**Rules:** `coding-standards.md` — Error Handling & Logging (structured log context). Module boundaries (Tracking never writes Task tables).

---

### 7. SlaTimerService — Core Timer Logic

**One-line summary:** Manages timer lifecycle — create, pause, resume, complete — and calculates working-calendar-aware deadlines. All mutations wrapped in `DB::transaction()` with try/catch and `Log::channel('tracking')`.

**Key decisions:**
- Constructor injects `WorkingDayCalculator` for deadline calculation
- `createTimerForStage()` / `createTimerForSubStage()` — idempotent with `exists()` check
- `pauseAllTimersForTask()` — updates all active timers for the task, calculates elapsed working seconds
- `resumeAllTimersForTask()` — recalculates `deadline_at` and `warning_at` based on remaining duration
- Calendar resolution: stage `owning_department_id` → department's working calendar → tenant default

**File:** `app/Modules/Tracking/Services/SlaTimerService.php`

**Code snippet — createTimerForStage:**
```php
public function createTimerForStage(TaskStageInstance $stageInstance): ?SlaTimerInstance
{
    try {
        return DB::transaction(function () use ($stageInstance) {
            $blueprintStage = $stageInstance->blueprintStage()->with('slaPolicy')->first();

            if (! $blueprintStage->sla_policy_id) {
                return null;
            }

            // Idempotent: check for existing active timer
            $existingTimer = SlaTimerInstance::where('stage_instance_id', $stageInstance->id)
                ->active()
                ->exists();

            if ($existingTimer) {
                return null; // Already has timer — skip
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
```

**Code snippet — deadline calculation:**
```php
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
```

**Code snippet — resolveWorkingCalendar:**
```php
private function resolveWorkingCalendar(?int $departmentId): WorkingCalendar
{
    if ($departmentId) {
        $department = Department::find($departmentId);
        // <!-- TODO: verify departments.working_calendar_id exists -->
        // If department has calendar, use it; else fall back
        if ($department && $department->working_calendar_id) {
            $calendar = WorkingCalendar::find($department->working_calendar_id);
            if ($calendar) {
                return $calendar;
            }
        }
    }

    // Tenant default calendar
    return WorkingCalendar::where('is_default', true)->firstOrFail();
}
```

**Code snippet — pauseAllTimersForTask:**
```php
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
```

**Code snippet — resumeAllTimersForTask:**
```php
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
                $totalDurationSeconds = $this->totalSlaDurationSeconds($calendar, $slaPolicy);
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
```

**Rules:** `coding-standards.md` — Database Transactions (all multi-write), Error Handling (try/catch + structured context), Events (`ShouldDispatchAfterCommit`). Module boundaries (never writes Task tables).

---

### 8. WorkingDayCalculator Extension

**One-line summary:** Add `addWorkingHours()`, `addWorkingSeconds()`, and `workingSecondsBetween()` methods to the existing `WorkingDayCalculator`.

**File:** `app/Modules/Organization/Services/WorkingDayCalculator.php`

**Code snippet — addWorkingHours:**
```php
public function addWorkingHours(WorkingCalendar $calendar, Carbon $fromDatetime, int $hours): Carbon
{
    return $this->addWorkingSeconds($calendar, $fromDatetime, $hours * 3600);
}

public function addWorkingSeconds(WorkingCalendar $calendar, Carbon $fromDatetime, int $seconds): Carbon
{
    $remaining = $seconds;
    $current = $fromDatetime->copy();

    $start = Carbon::createFromTimeString($calendar->working_hours_start);
    $end = Carbon::createFromTimeString($calendar->working_hours_end);
    $dailySeconds = $end->diffInSeconds($start);

    while ($remaining > 0) {
        if (! $this->isWorkingDay($calendar, $current)) {
            $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);
            continue;
        }

        $currentTime = $current->format('H:i:s');
        $endTime = $calendar->working_hours_end;

        if ($currentTime < $calendar->working_hours_start) {
            $current->setTimeFromTimeString($calendar->working_hours_start);
            $currentTime = $calendar->working_hours_start;
        }

        if ($currentTime >= $endTime) {
            $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);
            continue;
        }

        $secondsLeftInDay = Carbon::createFromTimeString($endTime)->diffInSeconds(Carbon::createFromTimeString($currentTime));

        if ($remaining <= $secondsLeftInDay) {
            $current->addSeconds($remaining);
            $remaining = 0;
        } else {
            $remaining -= $secondsLeftInDay;
            $current->addDay()->setTimeFromTimeString($calendar->working_hours_start);
        }
    }

    return $current;
}

public function workingSecondsBetween(WorkingCalendar $calendar, Carbon $from, Carbon $to): int
{
    if ($from->gte($to)) {
        return 0;
    }

    $totalSeconds = 0;
    $current = $from->copy();
    $start = $calendar->working_hours_start;
    $end = $calendar->working_hours_end;

    while ($current->lt($to)) {
        if (! $this->isWorkingDay($calendar, $current)) {
            $current->addDay()->startOfDay();
            continue;
        }

        $dayStart = $current->copy()->setTimeFromTimeString($start);
        $dayEnd = $current->copy()->setTimeFromTimeString($end);

        $effectiveStart = $current->gt($dayStart) ? $current->copy() : $dayStart->copy();
        $effectiveEnd = $to->lt($dayEnd) ? $to->copy() : $dayEnd->copy();

        if ($effectiveStart->lt($effectiveEnd)) {
            $totalSeconds += $effectiveEnd->diffInSeconds($effectiveStart);
        }

        $current = $dayEnd->copy()->addSecond();
    }

    return $totalSeconds;
}
```

**Test cases:**
1. `addWorkingHours(calendar, Monday 09:00, 8)` with 08:00–16:00 workday → Monday 17:00 (wait, 16:00 end means 8 hours = Tuesday 09:00? Depends on hours definition). Verify the expected datetime.
2. `workingSecondsBetween(calendar, Monday 14:00, Tuesday 10:00)` with 08:00–16:00 → 2h Monday + 2h Tuesday = 14400 seconds.

**Rules:** `coding-standards.md` — Module Communication (allowed: service method calls).

---

### 9. SlaThresholdService — Warning & Breach Detection

**One-line summary:** Scans due timers for warning/breach transitions, creates auto-escalations on breach. Used by `CheckSlaTimersJob`.

**Key decisions:**
- Uses `lockForUpdate()` on timer rows to prevent double-processing under concurrent scheduler runs
- Warning: `status` → `Warning`, emit `SlaWarningTriggered`
- Breach: `status` → `Breached`, emit `SlaBreached`, call `SlaEscalationService::createAutoEscalation()`
- Each timer is processed individually inside `DB::transaction()` so one failure doesn't block others

**File:** `app/Modules/Tracking/Services/SlaThresholdService.php`

**Code snippet — processWarnings:**
```php
public function processWarnings(): int
{
    $count = 0;

    SlaTimerInstance::dueWarning()
        ->chunkById(100, function ($timers) use (&$count) {
            foreach ($timers as $timer) {
                try {
                    DB::transaction(function () use ($timer) {
                        $fresh = SlaTimerInstance::lockForUpdate()->find($timer->id);
                        if (! $fresh || $fresh->status !== SlaTimerStatus::Running) {
                            return; // Already transitioned
                        }

                        $fresh->update(['status' => SlaTimerStatus::Warning]);
                        event(new SlaWarningTriggered($fresh));
                    });
                    $count++;
                } catch (\Throwable $e) {
                    Log::channel('tracking')->error('Failed to process SLA warning', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'sla_timer.warning',
                        'entity_type' => 'sla_timer_instance',
                        'entity_id' => $timer->public_id,
                        'performed_by' => 'system',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

    return $count;
}
```

**Code snippet — processBreaches:**
```php
public function processBreaches(): int
{
    $count = 0;

    SlaTimerInstance::dueBreach()
        ->chunkById(100, function ($timers) use (&$count) {
            foreach ($timers as $timer) {
                try {
                    DB::transaction(function () use ($timer) {
                        $fresh = SlaTimerInstance::lockForUpdate()->find($timer->id);
                        if (! $fresh || $fresh->status->isTerminal() || $fresh->status === SlaTimerStatus::Paused) {
                            return;
                        }

                        $fresh->update(['status' => SlaTimerStatus::Breached]);
                        event(new SlaBreached($fresh));

                        // Create automatic escalation
                        $this->escalationService->createAutoEscalation($fresh);
                    });
                    $count++;
                } catch (\Throwable $e) {
                    Log::channel('tracking')->error('Failed to process SLA breach', [
                        'tenant_slug' => tenant()?->slug ?? 'central',
                        'action' => 'sla_timer.breach',
                        'entity_type' => 'sla_timer_instance',
                        'entity_id' => $timer->public_id,
                        'performed_by' => 'system',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

    return $count;
}
```

**Test cases:**
1. Timer with `warning_at` in the past and `status = Running` → after `processWarnings()`, status = `Warning`
2. Timer with `deadline_at` in the past and `status = Warning` → after `processBreaches()`, status = `Breached` and one `Escalation` row created

**Rules:** `coding-standards.md` — Database Transactions, Error Handling, Chunk for Bulk Operations.

---

### 10. SlaEscalationService — Escalation Management

**One-line summary:** Creates auto-escalations on breach, handles manual escalation creation with ABAC, resolves escalations. All wrapped in `DB::transaction()` with try/catch.

**Key decisions:**
- `createAutoEscalation()` — resolves target from Blueprint `escalation_position_id` → fallback to assignee `reports_to_position_id` → current occupant. One escalation per unique target manager.
- `createManualEscalation()` — validates `task.escalate` capability, checks duplicate open escalation, resolves target.
- `resolveEscalation()` — validates caller is target user or has `task.resolve_escalations`, requires `resolution_note`.

**File:** `app/Modules/Tracking/Services/SlaEscalationService.php`

**Code snippet — createAutoEscalation:**
```php
public function createAutoEscalation(SlaTimerInstance $timer): void
{
    $stageInstance = $timer->stageInstance ?? $timer->subStageInstance?->parentStageInstance;
    if (! $stageInstance) {
        Log::channel('tracking')->warning('Cannot auto-escalate: no stage instance', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'escalation.auto_create',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => $timer->public_id,
            'performed_by' => 'system',
        ]);
        return;
    }

    $blueprintStage = $stageInstance->blueprintStage;
    $targets = $this->resolveEscalationTargets($blueprintStage, $stageInstance);

    if ($targets->isEmpty()) {
        Log::channel('tracking')->warning('No escalation target resolved for breached timer', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'escalation.auto_no_target',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => $timer->public_id,
            'performed_by' => 'system',
        ]);
        return;
    }

    foreach ($targets as $target) {
        $escalation = Escalation::create([
            'task_id' => $timer->task_id,
            'stage_instance_id' => $timer->stage_instance_id,
            'sub_stage_instance_id' => $timer->sub_stage_instance_id,
            'sla_timer_instance_id' => $timer->id,
            'escalation_type' => EscalationType::AutoSlaBreach,
            'escalated_to_user_id' => $target['user_id'],
            'escalated_to_position_id' => $target['position_id'],
            'escalated_by_user_id' => null, // System
            'reason' => 'SLA deadline breached.',
            'status' => EscalationStatus::Open,
        ]);

        event(new EscalationCreated($escalation));
    }
}
```

**Code snippet — resolveEscalationTargets:**
```php
private function resolveEscalationTargets(BlueprintStage $blueprintStage, TaskStageInstance $stageInstance): Collection
{
    $targets = collect();

    // 1. Check Blueprint escalation_position_id override
    if ($blueprintStage->escalation_position_id) {
        $position = Position::find($blueprintStage->escalation_position_id);
        if ($position) {
            $occupant = $position->currentOccupant;
            if ($occupant) {
                $targets->push([
                    'user_id' => $occupant->user_id,
                    'position_id' => $position->id,
                ]);
                return $targets;
            }
        }
    }

    // 2. Fall back to assignee reporting lines
    $activeAssignments = $stageInstance->assignments()
        ->where('is_completed', false)
        ->whereNull('reassigned_at')
        ->with('position.reportsTo.currentOccupant')
        ->get();

    $seenPositionIds = [];
    foreach ($activeAssignments as $assignment) {
        $position = $assignment->position;
        if (! $position || ! $position->reports_to_position_id) {
            continue;
        }

        $reportsToPosition = $position->reportsTo;
        if (! $reportsToPosition || in_array($reportsToPosition->id, $seenPositionIds)) {
            continue;
        }

        $occupant = $reportsToPosition->currentOccupant;
        if ($occupant) {
            $targets->push([
                'user_id' => $occupant->user_id,
                'position_id' => $reportsToPosition->id,
            ]);
            $seenPositionIds[] = $reportsToPosition->id;
        }
    }

    return $targets;
}
```

**Code snippet — resolveEscalation:**
```php
public function resolveEscalation(Escalation $escalation, User $user, string $resolutionNote): Escalation
{
    try {
        return DB::transaction(function () use ($escalation, $user, $resolutionNote) {
            if ($escalation->status === EscalationStatus::Resolved) {
                throw new EscalationAlreadyResolvedException;
            }

            // Check authorization: target user or has capability
            if ($escalation->escalated_to_user_id !== $user->id) {
                $hasCapability = app(IamPolicy::class)->check($user, 'task.resolve_escalations');
                if (! $hasCapability) {
                    throw new EscalationResolutionUnauthorizedException;
                }
            }

            $escalation->update([
                'status' => EscalationStatus::Resolved,
                'resolution_note' => $resolutionNote,
                'resolved_at' => now(),
            ]);

            event(new EscalationResolved($escalation));

            return $escalation->fresh();
        });
    } catch (EscalationAlreadyResolvedException|EscalationResolutionUnauthorizedException $e) {
        throw $e;
    } catch (\Throwable $e) {
        Log::channel('tracking')->error('Failed to resolve escalation', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'escalation.resolve',
            'entity_type' => 'escalation',
            'entity_id' => $escalation->public_id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Test cases:**
1. Breach a timer where Blueprint stage has `escalation_position_id` with active occupant → 1 escalation created for that occupant
2. Resolve open escalation by target user → `status = Resolved`, `resolved_at` set, `resolution_note` stored

**Rules:** `coding-standards.md` — Database Transactions, Error Handling, Module boundaries. `security-policy.md` — ABAC checked in service.

---

### 11. Scheduled Job & Command

**One-line summary:** `CheckSlaTimersCommand` runs on schedule, iterates tenants, dispatches `CheckSlaTimersJob` per tenant. Job calls `SlaThresholdService`.

**File:** `app/Modules/Tracking/Commands/CheckSlaTimersCommand.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Commands;

use App\Models\Tenant;
use App\Modules\Tracking\Jobs\CheckSlaTimersJob;
use Illuminate\Console\Command;

class CheckSlaTimersCommand extends Command
{
    protected $signature = 'tracking:check-sla-timers';
    protected $description = 'Dispatch SLA timer check jobs for all active tenants';

    public function handle(): int
    {
        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            CheckSlaTimersJob::dispatch($tenant->slug);
        }

        $this->info("Dispatched SLA checks for {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
```

**File:** `app/Modules/Tracking/Jobs/CheckSlaTimersJob.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Jobs;

use App\Modules\Tracking\Services\SlaThresholdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CheckSlaTimersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    public function __construct(public string $tenantSlug) {}

    public function handle(SlaThresholdService $thresholdService): void
    {
        // Tenant context is set by stancl/tenancy queue middleware
        $warnings = $thresholdService->processWarnings();
        $breaches = $thresholdService->processBreaches();

        Log::channel('tracking')->info('SLA timer check completed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'sla_timer.scheduled_check',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => null,
            'performed_by' => 'system',
            'warnings_processed' => $warnings,
            'breaches_processed' => $breaches,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('tracking')->error('SLA timer check job failed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'sla_timer.scheduled_check_failed',
            'entity_type' => 'sla_timer_instance',
            'entity_id' => null,
            'performed_by' => 'system',
            'error' => $exception->getMessage(),
        ]);
    }
}
```

**Schedule registration (in `routes/console.php` or `Kernel.php`):**
```php
Schedule::command('tracking:check-sla-timers')->everyMinute();
```

**Rules:** `coding-standards.md` — Queues & Jobs (tenant context, tries, backoff, failed handler).

---

### 12. Controllers

**One-line summary:** Two thin controllers. Rate limiting via `HasRateLimiting`. ABAC enforced in service/inline.

**File:** `app/Modules/Tracking/Controllers/SlaTimerController.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Tracking\Models\SlaTimerInstance;
use App\Modules\Tracking\Requests\ListSlaTimersRequest;
use App\Modules\Tracking\Resources\SlaTimerInstanceResource;
use App\Modules\Tracking\Resources\TaskSlaHealthResource;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;

class SlaTimerController extends Controller
{
    use HasRateLimiting;

    public function taskHealth(ListSlaTimersRequest $request, Task $task): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        // ABAC: verify task visibility
        $this->authorizeTaskVisibility($request->user(), $task);

        $timers = SlaTimerInstance::where('task_id', $task->id)
            ->with(['slaPolicy', 'stageInstance', 'subStageInstance'])
            ->get();

        return response()->json(new TaskSlaHealthResource($task, $timers));
    }

    public function index(ListSlaTimersRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = SlaTimerInstance::query()
            ->with(['task', 'slaPolicy', 'stageInstance', 'subStageInstance'])
            ->orderBy('id');

        // Apply filters from validated request
        $this->applyTimerFilters($query, $request->validated());

        return response()->json(
            SlaTimerInstanceResource::collection($query->cursorPaginate($request->integer('per_page', 15)))
        );
    }

    // private helper methods for ABAC + filter application...
}
```

**File:** `app/Modules/Tracking/Controllers/EscalationController.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tracking\Models\Escalation;
use App\Modules\Tracking\Requests\CreateManualEscalationRequest;
use App\Modules\Tracking\Requests\ListEscalationsRequest;
use App\Modules\Tracking\Requests\ResolveEscalationRequest;
use App\Modules\Tracking\Resources\EscalationDetailResource;
use App\Modules\Tracking\Resources\EscalationResource;
use App\Modules\Tracking\Services\SlaEscalationService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;

class EscalationController extends Controller
{
    use HasRateLimiting;

    public function __construct(private SlaEscalationService $escalationService) {}

    public function index(ListEscalationsRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $query = Escalation::query()
            ->with(['task', 'stageInstance', 'subStageInstance', 'escalatedToUser', 'escalatedByUser'])
            ->orderBy('id');

        $this->applyEscalationFilters($query, $request->validated(), $request->user());

        return response()->json(
            EscalationResource::collection($query->cursorPaginate($request->integer('per_page', 15)))
        );
    }

    public function show(Escalation $escalation): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()->public_id]);

        $escalation->load([
            'task', 'stageInstance', 'subStageInstance',
            'slaTimerInstance.slaPolicy', 'escalatedToUser',
            'escalatedToPosition', 'escalatedByUser',
        ]);

        return response()->json(new EscalationDetailResource($escalation));
    }

    public function store(CreateManualEscalationRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $escalation = $this->escalationService->createManualEscalation(
            $request->validated(),
            $request->user()
        );

        return response()->json(new EscalationDetailResource($escalation->load([
            'task', 'stageInstance', 'subStageInstance',
            'escalatedToUser', 'escalatedToPosition',
        ])), 201);
    }

    public function resolve(ResolveEscalationRequest $request, Escalation $escalation): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $escalation = $this->escalationService->resolveEscalation(
            $escalation,
            $request->user(),
            $request->validated('resolution_note')
        );

        return response()->json(new EscalationDetailResource($escalation));
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin), Rate Limiting (`HasRateLimiting`).

---

### 13. Form Requests

**One-line summary:** Four form request classes. `authorize()` returns `true` (ABAC in service).

**Code snippet — CreateManualEscalationRequest:**
```php
<?php

namespace App\Modules\Tracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateManualEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => ['required', 'string', 'uuid'],
            'stage_instance_id' => ['nullable', 'string', 'uuid'],
            'sub_stage_instance_id' => ['nullable', 'string', 'uuid'],
            'reason' => ['required', 'string', 'max:5000'],
            'escalated_to_position_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
```

**Code snippet — ResolveEscalationRequest:**
```php
<?php

namespace App\Modules\Tracking\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution_note' => ['required', 'string', 'max:5000'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation (Form Request classes).

---

### 14. API Resources

**One-line summary:** Four resource classes. `public_id` only, never internal `id`.

**Code snippet — SlaTimerInstanceResource:**
```php
<?php

namespace App\Modules\Tracking\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaTimerInstanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'stage_instance_id' => $this->stageInstance?->id, // <!-- TODO: verify if stages have public_id; they don't — use blueprint_stage public_id -->
            'sub_stage_instance_id' => $this->subStageInstance?->id, // Same note
            'sla_policy' => [
                'public_id' => $this->slaPolicy?->public_id,
                'name_ar' => $this->slaPolicy?->name_ar,
                'name_en' => $this->slaPolicy?->name_en ?? $this->slaPolicy?->name_ar,
                'sla_value' => $this->slaPolicy?->sla_value,
                'sla_unit' => $this->slaPolicy?->sla_unit,
            ],
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'warning_at' => $this->warning_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'elapsed_before_pause' => $this->elapsed_before_pause,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Code snippet — TaskSlaHealthResource:**
```php
<?php

namespace App\Modules\Tracking\Resources;

use App\Modules\Task\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class TaskSlaHealthResource extends JsonResource
{
    private Collection $timers;

    public function __construct(Task $task, Collection $timers)
    {
        parent::__construct($task);
        $this->timers = $timers;
    }

    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->public_id,
            'overall_health' => $this->computeOverallHealth(),
            'timers' => SlaTimerInstanceResource::collection($this->timers),
        ];
    }

    private function computeOverallHealth(): string
    {
        if ($this->timers->where('status.value', 3)->isNotEmpty()) {
            return 'breached';
        }
        if ($this->timers->where('status.value', 2)->isNotEmpty()) {
            return 'warning';
        }
        if ($this->timers->where('status.value', 1)->isNotEmpty()) {
            return 'on_track';
        }
        return 'none';
    }
}
```

**Rules:** `coding-standards.md` — API Resources (public_id only, never internal id).

---

### 15. Routes

**File:** `routes/api/v1/tracking.php`

**Code snippet:**
```php
<?php

use App\Modules\Tracking\Controllers\EscalationController;
use App\Modules\Tracking\Controllers\SlaTimerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('tracking')->group(function () {
    // SLA Timer APIs
    Route::prefix('sla')->group(function () {
        Route::get('tasks/{task}', [SlaTimerController::class, 'taskHealth']);
        Route::get('timers', [SlaTimerController::class, 'index']);
    });

    // Escalation APIs
    Route::prefix('escalations')->group(function () {
        Route::get('/', [EscalationController::class, 'index']);
        Route::get('{escalation}', [EscalationController::class, 'show']);
        Route::middleware(['capability:task.escalate'])->group(function () {
            Route::post('/', [EscalationController::class, 'store']);
        });
        Route::post('{escalation}/resolve', [EscalationController::class, 'resolve']);
    });
});
```

**Rules:** `coding-standards.md` — Rate Limiting (applied in controllers, not routes).

---

### 16. Seeder Updates

**File:** `database/seeders/CapabilitySeeder.php`

**Add:**
```php
['key' => 'task.escalate', 'name_en' => 'Escalate Task', 'name_ar' => 'تصعيد مهمة', 'description_en' => 'Create manual escalations for at-risk stages', 'description_ar' => 'إنشاء تصعيدات يدوية للمراحل المعرضة للخطر'],
['key' => 'task.resolve_escalations', 'name_en' => 'Resolve Escalations', 'name_ar' => 'حل التصعيدات', 'description_en' => 'Resolve escalations beyond own assignments', 'description_ar' => 'حل التصعيدات خارج نطاق المهام المسندة'],
```

---

### 17. Logging Channel

**File:** `config/logging.php` — add `tracking` channel (already configured per `coding-standards.md`; verify it exists, add if missing):

```php
'tracking' => [
    'driver' => 'daily',
    'path' => storage_path('logs/tracking.log'),
    'level' => 'debug',
    'days' => 14,
],
```

---

### 18. Service Provider

**File:** `app/Modules/Tracking/Providers/TrackingServiceProvider.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Tracking\Providers;

use App\Modules\Task\Events\StageInstanceCreated;
use App\Modules\Task\Events\StageInstanceCompleted;
use App\Modules\Task\Events\StageInstanceReturned;
use App\Modules\Task\Events\SubStageInstanceCreated;
use App\Modules\Task\Events\SubStageInstanceCompleted;
use App\Modules\Task\Events\TaskCancelled;
use App\Modules\Task\Events\TaskCompleted;
use App\Modules\Task\Events\TaskResumed;
use App\Modules\Task\Events\TaskSuspended;
use App\Modules\Tracking\Listeners\HandleStageInstanceCreated;
use App\Modules\Tracking\Listeners\HandleStageInstanceCompleted;
use App\Modules\Tracking\Listeners\HandleStageInstanceReturned;
use App\Modules\Tracking\Listeners\HandleSubStageInstanceCreated;
use App\Modules\Tracking\Listeners\HandleSubStageInstanceCompleted;
use App\Modules\Tracking\Listeners\HandleTaskCompletedOrCancelled;
use App\Modules\Tracking\Listeners\HandleTaskResumed;
use App\Modules\Tracking\Listeners\HandleTaskSuspended;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class TrackingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(StageInstanceCreated::class, HandleStageInstanceCreated::class);
        Event::listen(SubStageInstanceCreated::class, HandleSubStageInstanceCreated::class);
        Event::listen(StageInstanceCompleted::class, HandleStageInstanceCompleted::class);
        Event::listen(SubStageInstanceCompleted::class, HandleSubStageInstanceCompleted::class);
        Event::listen(StageInstanceReturned::class, HandleStageInstanceReturned::class);
        Event::listen(TaskSuspended::class, HandleTaskSuspended::class);
        Event::listen(TaskResumed::class, HandleTaskResumed::class);
        Event::listen(TaskCompleted::class, HandleTaskCompletedOrCancelled::class);
        Event::listen(TaskCancelled::class, HandleTaskCompletedOrCancelled::class);
    }
}
```

Register in `config/app.php` providers array or `AppServiceProvider`.

---

## Execution Order

1. **Enums** — Create all 3 enum classes (no dependencies)
2. **Migrations** — Create 2 migration files; run `php artisan migrate` on tenant template
3. **Models** — Create `SlaTimerInstance`, `Escalation` with relationships and casts
4. **Factories** — Create `SlaTimerInstanceFactory`, `EscalationFactory`
5. **Exceptions** — Create all 7 exception classes
6. **Events** — Create all 8 event classes
7. **Logging** — Add/verify `tracking` channel in `config/logging.php`
8. **WorkingDayCalculator extension** — Add `addWorkingHours()`, `addWorkingSeconds()`, `workingSecondsBetween()`
9. **Services** — Create `SlaTimerService`, `SlaEscalationService`, `SlaThresholdService`
10. **Listeners** — Create all 8 listener classes
11. **Service Provider** — Create `TrackingServiceProvider`, register in app
12. **Requests** — Create all 4 Form Request classes
13. **Resources** — Create all 4 API Resource classes
14. **Controllers** — Create `SlaTimerController`, `EscalationController`
15. **Routes** — Create `routes/api/v1/tracking.php`; include in `routes/api.php`
16. **Seeder** — Add `task.escalate` and `task.resolve_escalations` to `CapabilitySeeder`
17. **Job & Command** — Create `CheckSlaTimersJob`, `CheckSlaTimersCommand`; register in scheduler
18. **Tests** — Create all 7 test files
19. **Run tests** — `php artisan test --compact`
20. **Run Pint** — `vendor/bin/pint --dirty --format agent`

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Rate Limit | Description |
|--------|----------|------|------------|------------|-------------|
| GET | `/api/v1/tracking/sla/tasks/{task}` | Sanctum | — (ABAC visibility) | LIST | SLA health for a task |
| GET | `/api/v1/tracking/sla/timers` | Sanctum | — (ABAC filtered) | LIST | Cursor-paginated timer list |
| GET | `/api/v1/tracking/escalations` | Sanctum | — (ABAC filtered) | LIST | Cursor-paginated escalation list |
| GET | `/api/v1/tracking/escalations/{escalation}` | Sanctum | — (ABAC filtered) | LIST | Escalation detail |
| POST | `/api/v1/tracking/escalations` | Sanctum | `task.escalate` | MUTATE | Create manual escalation |
| POST | `/api/v1/tracking/escalations/{escalation}/resolve` | Sanctum | target user or `task.resolve_escalations` | MUTATE | Resolve escalation |

**Pagination:**
- `GET .../sla/timers` — cursor pagination (`orderBy('id')`, `{data, next_cursor, has_more}`)
- `GET .../escalations` — cursor pagination
- `GET .../sla/tasks/{task}` — full bounded object (no pagination)

**Error responses:**

| Status | Exception | When |
|--------|-----------|------|
| 422 | `SlaPolicyMissingException` | Stage referenced non-existent SLA policy |
| 422 | `SlaTimerAlreadyExistsException` | Duplicate active timer (idempotency guard) |
| 422 | `SlaTimerNotActiveException` | Timer not in actionable state |
| 422 | `EscalationTargetNotFoundException` | No target resolved for escalation |
| 422 | `DuplicateOpenEscalationException` | Same user, same target, same stage already open |
| 422 | `EscalationAlreadyResolvedException` | Escalation already resolved |
| 403 | `EscalationResolutionUnauthorizedException` | Not target user and lacks capability |
| 403 | `abort()` | Missing `task.escalate` capability on manual escalation |

---

## What to Test Manually

1. **Timer creation on stage entry:** Launch a task with an SLA-enabled blueprint → verify `sla_timer_instances` row created with correct `deadline_at` based on working calendar.
2. **No-SLA stage:** Launch a task with a stage that has no SLA policy → verify no timer row; `GET .../sla/tasks/{task}` shows `sla_health = none` for that stage.
3. **Warning threshold:** Create a timer with a short duration (e.g., 2-hour SLA, 80% warning) → advance time past `warning_at` → run `tracking:check-sla-timers` → verify status changes to `Warning` and `SlaWarningTriggered` event emitted.
4. **Breach threshold:** Advance time past `deadline_at` → run scheduler → verify status = `Breached`, `SlaBreached` event emitted, and automatic escalation created.
5. **Auto-escalation with Blueprint override:** Stage has `escalation_position_id` set → breach → verify escalation targets the override position's occupant.
6. **Auto-escalation with reporting line:** Stage has no `escalation_position_id` → breach → verify escalation targets the assignee's `reports_to_position_id` occupant.
7. **Multiple assignees → multiple escalations:** Stage with 2 assignees reporting to 2 different managers → breach → verify 2 escalation rows created (one per unique manager).
8. **No escalation target:** Stage where assignee has no reporting line and no override → breach → verify timer breached, no escalation, warning logged.
9. **Task suspension pauses timers:** Active timer → suspend task → verify timer status = `Paused`, `paused_at` set, `elapsed_before_pause` calculated.
10. **Task resume recalculates deadlines:** Resume suspended task → verify timer status = `Running` or `Warning`, `deadline_at` and `warning_at` recalculated.
11. **Stage completion completes timer:** Complete a stage with an active timer → verify timer status = `Completed`, `completed_at` set.
12. **Stage return completes old timer:** Return stage → verify old timer `Completed`, new stage entry creates a new timer.
13. **Task cancel/complete clears all timers:** Cancel/complete a task with multiple active timers → verify all timers marked `Completed`.
14. **Manual escalation — happy path:** POST escalation with `task.escalate` capability → verify escalation created, status = `Open`.
15. **Manual escalation — ABAC denial:** User without `task.escalate` → POST → verify 403.
16. **Manual escalation — duplicate prevention:** Same user, same stage, open escalation exists → POST → verify 422.
17. **Escalation resolution by target manager:** Target user resolves with `resolution_note` → verify `Resolved`, `resolved_at`, `EscalationResolved` event.
18. **Escalation resolution by capability holder:** User with `task.resolve_escalations` resolves → verify success.
19. **Escalation resolution — unauthorized:** Non-target user without capability → verify 403.
20. **Escalation resolution — already resolved:** Attempt to resolve already-resolved escalation → verify 422.
21. **Timer list — cursor pagination:** Create 20+ timers → `GET .../sla/timers?per_page=5` → verify `{data, next_cursor, has_more}` contract.
22. **Escalation list — filters:** Create mix of Open/Resolved, Auto/Manual → filter by `status=open` → verify only open returned.
23. **Concurrent scheduler safety:** Run `tracking:check-sla-timers` twice simultaneously → verify no double-warn or double-breach.
24. **Rate limiting:** Hit timer list endpoint 61 times in 1 minute → verify 429 on 61st request.
25. **Idempotent timer creation:** Replay `StageInstanceCreated` event → verify no duplicate timer created.
