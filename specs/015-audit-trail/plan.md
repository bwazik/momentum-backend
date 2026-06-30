# Plan: Audit Trail (Spec 015)

> **Spec:** 015-audit-trail
> **Date:** 2026-06-30  
> **Status:** completed  

---

## Open Questions Resolved

| # | Spec Open Question | Decision | Rationale |
|---|-------------------|----------|-----------|
| 1 | Synchronous or queued listener? | **Synchronous** for MVP. | Domain events already implement `ShouldDispatchAfterCommit`, so the listener runs after the originating transaction commits. The audit insert is a single lightweight row. Queue deferred to V2 if load testing shows impact. |
| 2 | Full snapshots vs event metadata in `payload`? | **Event-specific metadata only.** | Each event already carries the relevant models/scalars. Mapper closures extract a bounded JSON payload. Never query back to other modules (preserves Audit module boundary). |
| 3 | Hide `ip_address`/`user_agent` from non-admins? | **`audit.view_system` includes IP/UA; `GET /audit-trail/me` omits them.** | System auditors need full forensics; personal activity view is privacy-sensitive. |
| 4 | Events emitted before migration exists? | **No backfill.** | Audit trail starts from module deployment. Historical events are already consumed by Notification/Analytics and are not recoverable. |
| 5 | Centralized vs split listeners? | **Interface-based: each event implements `ProvidesAuditData`.** | A single `RecordAuditEvent` listener checks the interface. Each event defines its own `auditData()` — eliminating the mapper registry and keeping audit logic co-located with the event. |
| 6 | How to query complete task history including child entities? | **Add `root_entity_type`/`root_entity_id` denormalized columns.** | Required for efficient chronological task trail (task + stages + sub-stages + documents + escalations + SLA timers + follow-up actions) without cross-module joins. Updated in `spec.md`. |
| 7 | How to record platform-admin impersonation? | **Use `impersonated_by_public_id` (UUID string), not an FK.** | Platform admins live in the central DB, not tenant `users`. Token ability `impersonated-by:{public_id}` provides the value. Updated in `spec.md`. |

---

## Technical Approach

Create a new **Audit** bounded context that listens to all tenant domain events, persists them to an append-only `audit_events` table with denormalized `root_entity_*` metadata, and exposes three cursor-paginated read APIs protected by ABAC and task visibility rules.

### Key Decisions

- **Append-only storage:** No update/delete model methods or API endpoints; immutable by design.
- **Interface-based audit data:** Each event implements `ProvidesAuditData` and returns an `AuditEventData` DTO. The listener is generic (~60 lines) and never grows.
- **Root-entity denormalization:** Task-related child events store `root_entity_type = 'task'` and `root_entity_id` so the task audit trail is a single indexed query. `root_entity_public_id` also stored to avoid N+1 at read time.
- **Synchronous recording:** Listener runs inline after `ShouldDispatchAfterCommit`; never throws back to the originating transaction.
- **Auto-discovery via audit service provider:** `AuditServiceProvider` scans all modules for `ProvidesAuditData` implementors and registers them with the listener — no manual event list.
- **Capabilities already seeded:** `audit.view_task`, `audit.view_system`, and `audit.create_grant` already exist in `CapabilitySeeder`; no seeder changes required for MVP.
- **Central audit aligned:** Platform events also implement `ProvidesAuditData`. A `RecordCentralAuditEvent` listener writes to the central `audit_events` table with matching schema.

---

## Affected Modules / Files

### New Files

| Path | Purpose |
|------|---------|
| `database/tenant/2026_06_30_000001_create_audit_events_table.php` | Tenant migration for `audit_events`. |
| `app/Modules/Audit/Data/AuditEventData.php` | Typed DTO for audit event data returned by each event's `auditData()`. |
| `app/Modules/Audit/Contracts/ProvidesAuditData.php` | Interface each auditable event implements; provides `auditData(): AuditEventData`. |
| `app/Modules/Audit/Models/AuditEvent.php` | Eloquent model; append-only guards, casts, scopes. |
| `app/Modules/Audit/Enums/AuditEntityType.php` | Int-backed enum for known entity categories (31 cases). |
| `app/Modules/Audit/Events/AuditEventRecorded.php` | Domain event emitted after each audit insert. |
| `app/Modules/Audit/Listeners/RecordAuditEvent.php` | Generic listener (~60 lines) — checks `ProvidesAuditData`, calls `auditData()`, persists. |
| `app/Modules/Audit/Providers/AuditServiceProvider.php` | Auto-discovers `ProvidesAuditData` implementors and registers them with the listener. |
| `app/Modules/Audit/Services/AuditEventService.php` | Read services: task trail, system log, my activity. |
| `app/Modules/Audit/Controllers/AuditTrailController.php` | Thin controller for the three read endpoints. |
| `app/Modules/Audit/Resources/AuditEventResource.php` | API resource; omits IP/UA for `me` endpoint. |
| `app/Modules/Audit/Requests/ListAuditTrailRequest.php` | Validation for task audit trail filters. |
| `app/Modules/Audit/Requests/ListSystemAuditRequest.php` | Validation for system log filters. |
| `app/Modules/Audit/Requests/ListMyActivityRequest.php` | Validation for my-activity filters. |
| `routes/api/v1/audit.php` | Route definitions. |
| `app/Modules/Platform/Listeners/RecordCentralAuditEvent.php` | Central audit listener (same pattern, writes to central DB). |
| `app/Modules/Platform/Providers/CentralAuditServiceProvider.php` | Auto-discovers Platform `ProvidesAuditData` events for central audit. |
| `tests/Feature/Modules/Audit/AuditTrailTest.php` | Task audit trail feature tests. |
| `tests/Feature/Modules/Audit/SystemAuditTest.php` | System activity log feature tests. |
| `tests/Feature/Modules/Audit/MyActivityTest.php` | My-activity feature tests. |
| `tests/Feature/Modules/Audit/AuditEventPersistenceTest.php` | Append-only, listener safety, impersonation persistence tests. |
| `tests/Feature/Modules/Audit/AuditTenantIsolationTest.php` | Tenant isolation tests. |

### Modified Files

| Path | Change |
|------|--------|
| `config/logging.php` | Add `audit` daily channel with 30-day retention. |
| `routes/tenant.php` | `require __DIR__.'/api/v1/audit.php';` |
| `bootstrap/providers.php` | Register `AuditServiceProvider` and `CentralAuditServiceProvider`. |
| `app/Modules/Platform/Models/AuditEvent.php` | Updated fillable/casts to match aligned central schema; added immutability guards. |
| `database/migrations/.../create_central_audit_events_table.php` | Added `event_type`, `entity_type_int`, `root_entity_*`, `impersonated_by_public_id` columns; made `action` nullable. |
| `specs/015-audit-trail/spec.md` | Updated migration schema, event capture approach, enum cases, open questions. |
| Every event class across Task, IAM, Organization, Blueprint, Document, FollowUp, Tracking, Platform | Added `implements ProvidesAuditData` + `auditData()` method. |

---

## Implementation Notes

### 1. Migration — `database/tenant/2026_06_30_000001_create_audit_events_table.php`

**One-line summary:** Append-only tenant table with polymorphic entity columns, denormalized root entity, and targeted composite indexes.

**Key decisions:**
- No `updated_at`; `created_at` is the audit timestamp.
- No soft deletes; rows are immutable.
- `impersonated_by_public_id` is a UUID string (not FK) because platform admins live in central DB.
- Indexes support the three read patterns: per-entity, per-task-root, per-user, per-event-type.

**Exact file to edit:** `database/tenant/2026_06_30_000001_create_audit_events_table.php`

**Actual snippet:**

```php
public function up(): void
{
    Schema::create('audit_events', function (Blueprint $table) {
        $table->id();
        $table->uuid('public_id')->unique();
        $table->string('event_type', 100);
        $table->unsignedTinyInteger('entity_type');
        $table->unsignedBigInteger('entity_id');
        $table->uuid('entity_public_id')->nullable();
        $table->unsignedTinyInteger('root_entity_type')->nullable();
        $table->unsignedBigInteger('root_entity_id')->nullable();
        $table->uuid('root_entity_public_id')->nullable();
        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('ip_address', 45)->nullable();
        $table->string('user_agent', 500)->nullable();
        $table->jsonb('payload')->nullable();
        $table->uuid('impersonated_by_public_id')->nullable();
        $table->timestamp('created_at')->useCurrent();

        $table->index(['entity_type', 'entity_id', 'created_at']);
        $table->index(['root_entity_type', 'root_entity_id', 'created_at']);
        $table->index(['user_id', 'created_at']);
        $table->index(['event_type', 'created_at']);
    });
}
```

**Rules:** `coding-standards.md` — Migrations in `database/tenant/`; no `tenant_id`; tenant DB models have no `tenant_id` column.

**Test cases:**
1. Run migration on tenant DB → `audit_events` table exists with expected columns and indexes.
2. Run `migrate:rollback` → table is dropped cleanly.

---

### 2. Enum — `app/Modules/Audit/Enums/AuditEntityType.php`

**One-line summary:** Int-backed enum identifying the entity category stored in `entity_type` and `root_entity_type`.

**Exact file to edit:** `app/Modules/Audit/Enums/AuditEntityType.php`

**Copy-paste snippet:**

```php
enum AuditEntityType: int
{
    case Task = 1;
    case StageInstance = 2;
    case SubStageInstance = 3;
    case User = 4;
    case Position = 5;
    case Department = 6;
    case Blueprint = 7;
    case Document = 8;
    case Escalation = 9;
    case SlaTimerInstance = 10;
    case FollowUpAction = 11;
    case Comment = 12;
    case HelpArticle = 13;
    case OnboardingJourney = 14;
    case Tenant = 15;
    case PlatformAdmin = 16;
    case Impersonation = 17;
    case WorkingCalendar = 18;
    case PublicHoliday = 19;
    case AuthorityGrade = 20;
    case PositionAssignment = 21;
    case Delegation = 22;
    case MonitoringScopeGrant = 23;
    case AuditGrant = 24;
    case CapabilityGrant = 25;
    case StageType = 26;
    case SlaPolicy = 27;
    case BlueprintCategory = 28;
    case BlueprintStage = 29;
    case BlueprintSubStage = 30;
    case BlueprintTransition = 31;
    // name() method returns snake_case strings for each case
}
```

**Rules:** `coding-standards.md` — Enums stored as TINYINT + PHP enum class; TitleCase keys; module-specific enums under `app/Modules/{Module}/Enums/`.

---

### 3. Model — `app/Modules/Audit/Models/AuditEvent.php`

**One-line summary:** Tenant model with `public_id` generation, append-only guards, enum casts, and read scopes.

**Exact file to edit:** `app/Modules/Audit/Models/AuditEvent.php`

**Copy-paste snippet:**

```php
<?php

namespace App\Modules\Audit\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'event_type',
    'entity_type',
    'entity_id',
    'entity_public_id',
    'root_entity_type',
    'root_entity_id',
    'root_entity_public_id',
    'user_id',
    'ip_address',
    'user_agent',
    'payload',
    'impersonated_by_public_id',
])]
class AuditEvent extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entity_type' => AuditEntityType::class,
            'root_entity_type' => AuditEntityType::class,
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForRootEntity($query, AuditEntityType $type, int $id)
    {
        return $query->where('root_entity_type', $type)->where('root_entity_id', $id);
    }

    public function scopeForEntity($query, AuditEntityType $type, int $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
```

**Rules:** `coding-standards.md` — Models use `TenantModel` + `HasPublicId`; no `tenant_id`; route model binding by `public_id`; casts in `casts()` method; PHP 8 `#[Fillable]` attribute.

**Test cases:**
1. `AuditEvent::create([...])` succeeds and auto-generates `public_id`.
2. `$event->update([...])` returns `false`; `$event->delete()` returns `false`.

---

### 4. Domain Event — `app/Modules/Audit/Events/AuditEventRecorded.php`

**One-line summary:** Fired after each audit row insert so V2 modules can react (e.g., index for export).

**Exact file to edit:** `app/Modules/Audit/Events/AuditEventRecorded.php`

**Copy-paste snippet:**

```php
<?php

namespace App\Modules\Audit\Events;

use App\Modules\Audit\Models\AuditEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AuditEventRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public AuditEvent $auditEvent) {}
}
```

**Rules:** `coding-standards.md` — All domain events implement `ShouldDispatchAfterCommit`.

---

### 5. DTO + Interface + Listener — `app/Modules/Audit/Data/AuditEventData.php`, `app/Modules/Audit/Contracts/ProvidesAuditData.php`, `app/Modules/Audit/Listeners/RecordAuditEvent.php`

**One-line summary:** Each auditable event implements `ProvidesAuditData` returning an `AuditEventData` DTO. The generic listener (~60 lines) checks the interface, calls `auditData()`, and persists. No mapper registry.

**DTO — `app/Modules/Audit/Data/AuditEventData.php`:** Typed DTO with `eventType`, `entityType`, `entityId`, `entityPublicId`, `rootEntityType`, `rootEntityId`, `rootEntityPublicId`, `user`, `payload`.

**Interface — `app/Modules/Audit/Contracts/ProvidesAuditData.php`:** Single method `auditData(): AuditEventData`.

**Listener — `app/Modules/Audit/Listeners/RecordAuditEvent.php`:** Checks `instanceof ProvidesAuditData`, calls `auditData()`, creates `AuditEvent` row. Catches `\Throwable`, logs to `audit` channel. Never re-throws. Resolves `user_id` from DTO or `request()->user()`. Resolves impersonation from Sanctum token abilities.

**Provider — `app/Modules/Audit/Providers/AuditServiceProvider.php`:** Scans all `app/Modules/*/Events/` (excluding Platform/Audit) for `ProvidesAuditData` implementors and registers each with the listener via `Event::listen()`. Platform events registered separately with `RecordCentralAuditEvent`.

**Sample event implementing `ProvidesAuditData`:**

```php
class TaskCreated implements ShouldDispatchAfterCommit, ProvidesAuditData
{
    public function __construct(public Task $task) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'task.created',
            entityType: AuditEntityType::Task,
            entityId: $this->task->id,
            entityPublicId: $this->task->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->task->id,
            rootEntityPublicId: $this->task->public_id,
            user: $this->task->initiator,
            payload: ['title_ar' => $this->task->title_ar, 'title_en' => $this->task->title_en],
        );
    }
}
```

**Test cases:**
1. Fire `TaskCreated` → one `audit_events` row with `event_type='task.created'`, `entity_type=Task`, `root_entity_type=Task`.
2. Fire `StageInstanceCompleted` for task T → row has `entity_type=StageInstance`, `root_entity_type=Task`, `root_entity_id=T.id`.

---

### 6. Service — `app/Modules/Audit/Services/AuditEventService.php`

**One-line summary:** Read-only query builder for task trail, system log, and my activity with ABAC, visibility, and external-auditor filters.

**Key decisions:**
- Task audit trail uses `root_entity_type = Task` + `root_entity_id = task.id`.
- System log and my activity support `user_id`, `event_type`, `entity_type`, `date_from`, `date_to` filters.
- External auditors are blocked unless they have an active `audit_grant` covering a completed/cancelled task.
- No caching on list results; filter catalogs cached separately.

**Exact file to edit:** `app/Modules/Audit/Services/AuditEventService.php`

**Copy-paste snippet:**

```php
<?php

namespace App\Modules\Audit\Services;

use App\Enums\AccountType;
use App\Models\User;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Iam\Models\AuditGrant;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Traits\AuthenticatedUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditEventService
{
    use AuthenticatedUser;

    public function __construct(
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function taskTrail(Task $task, Request $request, User $user): CursorPaginator
    {
        try {
            if ($user->isExternalAuditor()) {
                $this->guardExternalAuditorAccess($task, $user);
            } else {
                if (! $this->iamPolicy->hasCapability($user, 'audit.view_task')) {
                    abort(403, 'Missing audit.view_task capability.');
                }

                $visible = $this->taskVisibilityScope
                    ->apply(\App\Modules\Task\Models\Task::query()->where('id', $task->id), $user)
                    ->exists();

                if (! $visible) {
                    abort(403, 'You do not have access to this task.');
                }
            }

            $query = AuditEvent::forRootEntity(AuditEntityType::Task, $task->id)
                ->with('user')
                ->orderBy('id');

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load task audit trail', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.task_trail',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function systemLog(Request $request, User $user): CursorPaginator
    {
        try {
            if (! $this->iamPolicy->hasCapability($user, 'audit.view_system')) {
                abort(403, 'Missing audit.view_system capability.');
            }

            $query = AuditEvent::query()->with('user')->orderBy('id');
            $this->applyFilters($query, $request);

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load system audit log', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.system_log',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function myActivity(Request $request, User $user): CursorPaginator
    {
        try {
            $query = AuditEvent::query()
                ->where('user_id', $user->id)
                ->with('user')
                ->orderBy('id');

            $this->applyFilters($query, $request);

            return $this->applyCursorPagination($query, $request);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('Failed to load my activity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'audit.my_activity',
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function guardExternalAuditorAccess(Task $task, User $user): void
    {
        if (! in_array($task->status->value, [\App\Modules\Task\Enums\TaskStatus::Completed->value, \App\Modules\Task\Enums\TaskStatus::Cancelled->value], true)) {
            abort(403, 'External auditors can only view completed or cancelled tasks.');
        }

        $taskDeptId = $task->initiator?->currentPositionAssignment?->position?->department_id;

        $hasGrant = AuditGrant::where('external_auditor_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('date_range_start', '<=', now())
            ->where('date_range_end', '>=', now())
            ->where(function ($q) use ($taskDeptId) {
                $q->whereNull('department_id')
                    ->orWhere('department_id', $taskDeptId);
            })
            ->exists();

        if (! $hasGrant) {
            abort(403, 'No active audit grant covers this task.');
        }
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('user_id')) {
            $userId = User::where('public_id', $request->input('user_id'))->value('id');
            if ($userId) {
                $query->where('user_id', $userId);
            }
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('entity_type')) {
            $type = AuditEntityType::tryFrom($request->integer('entity_type'));
            if ($type) {
                $query->where('entity_type', $type);
            }
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }
    }

    private function applyCursorPagination($query, Request $request): CursorPaginator
    {
        $perPage = min(100, max(1, $request->integer('per_page', 15)));

        return $query->cursorPaginate($perPage);
    }
}
```

**Rules:** `coding-standards.md` — Cursor pagination on large tables; `orderBy('id')`; `per_page` bounded [1,100]; try/catch + module logging; ABAC checks via `IamPolicy`.

**Test cases:**
1. User with `audit.view_task` views task trail → receives task + child entity events sorted by `id`.
2. User without capability → 403.

---

### 7. Requests

**Files:**
- `app/Modules/Audit/Requests/ListAuditTrailRequest.php`
- `app/Modules/Audit/Requests/ListSystemAuditRequest.php`
- `app/Modules/Audit/Requests/ListMyActivityRequest.php`

**Copy-paste snippet for `ListSystemAuditRequest`:**

```php
<?php

namespace App\Modules\Audit\Requests;

use App\Modules\Audit\Enums\AuditEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSystemAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'uuid'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', Rule::enum(AuditEntityType::class)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
```

`ListMyActivityRequest` is identical. `ListAuditTrailRequest` only validates `per_page` and `cursor`.

**Rules:** `coding-standards.md` — Form Request classes for validation; enum validation via `Rule::enum()`; never raw integer `in:` rules.

---

### 8. Resource — `app/Modules/Audit/Resources/AuditEventResource.php`

**One-line summary:** Expose audit row with performer details; omit IP/UA for personal activity endpoint via context flag.

**Exact file to edit:** `app/Modules/Audit/Resources/AuditEventResource.php`

**Copy-paste snippet:**

```php
<?php

namespace App\Modules\Audit\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeSensitive = $request->route()?->getName() !== 'audit.my_activity';

        return [
            'public_id' => $this->public_id,
            'event_type' => $this->event_type,
            'entity_type' => $this->entity_type?->name(),
            'entity_id' => $this->entity_public_id,
            'root_entity_type' => $this->root_entity_type?->name(),
            'root_entity_id' => $this->root_entity_public_id,
            'performed_by' => $this->user ? [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en,
            ] : null,
            'ip_address' => $includeSensitive ? $this->ip_address : null,
            'user_agent' => $includeSensitive ? $this->user_agent : null,
            'impersonated_by_public_id' => $this->impersonated_by_public_id,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

}
```

**Rules:** `coding-standards.md` — API Resources required; expose `public_id` only, never internal `id`; eager-load relationships.

---

### 9. Controller — `app/Modules/Audit/Controllers/AuditTrailController.php`

**One-line summary:** Thin controller validating rate limits, delegating to service, returning cursor-paginated audit resources.

**Exact file to edit:** `app/Modules/Audit/Controllers/AuditTrailController.php`

**Copy-paste snippet:**

```php
<?php

namespace App\Modules\Audit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Requests\ListAuditTrailRequest;
use App\Modules\Audit\Requests\ListMyActivityRequest;
use App\Modules\Audit\Requests\ListSystemAuditRequest;
use App\Modules\Audit\Resources\AuditEventResource;
use App\Modules\Audit\Services\AuditEventService;
use App\Modules\Task\Models\Task;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private AuditEventService $auditEventService,
    ) {}

    public function taskTrail(ListAuditTrailRequest $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->taskTrail($task, $request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function systemLog(ListSystemAuditRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->systemLog($request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function myActivity(ListMyActivityRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->auditEventService->myActivity($request, $request->user())
            ->through(fn ($event) => new AuditEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**Rules:** `coding-standards.md` — Controllers thin; rate limiting via `HasRateLimiting` trait; cursor pagination returns `{data, next_cursor, has_more}`.

---

### 10. Routes — `routes/api/v1/audit.php`

**Exact file to edit:** `routes/api/v1/audit.php`

**Copy-paste snippet:**

```php
<?php

use App\Modules\Audit\Controllers\AuditTrailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('tasks/{task}/audit-trail', [AuditTrailController::class, 'taskTrail'])
        ->name('audit.task-trail');

    Route::get('audit-trail/system', [AuditTrailController::class, 'systemLog'])
        ->name('audit.system-log');

    Route::get('audit-trail/me', [AuditTrailController::class, 'myActivity'])
        ->name('audit.my-activity');
});
```

**Register in `routes/tenant.php`:**

```php
require __DIR__.'/api/v1/audit.php';
```

**Rules:** `coding-standards.md` — Routes under `/api/v1/`; auth via Sanctum; capability checks in service layer (already used by `IamPolicy`).

---

### 11. Logging Config — `config/logging.php`

**Exact change:** Add inside `channels` array before `emergency`:

```php
'audit' => [
    'driver' => 'daily',
    'path' => storage_path('logs/audit/audit.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 30,
    'replace_placeholders' => true,
],
```

**Rules:** `coding-standards.md` — Per-module logging channel; structured context; 30-day retention for audit.

---

### 12. Tests

**Files:**
- `tests/Feature/Modules/Audit/AuditTrailTest.php`
- `tests/Feature/Modules/Audit/SystemAuditTest.php`
- `tests/Feature/Modules/Audit/MyActivityTest.php`
- `tests/Feature/Modules/Audit/AuditEventPersistenceTest.php` (append-only, listener safety, impersonation)
- `tests/Feature/Modules/Audit/AuditTenantIsolationTest.php` (tenant isolation)

**Test patterns:** Follow `testing-policy.md` Pattern A (tenant provision + login + `RefreshDatabase`).

**Minimum test cases:**
1. Task creation emits audit row; task trail returns it.
2. Stage completion emits audit row with `root_entity_type=task` and `root_entity_id=task.id`.
3. Missing `audit.view_task` capability → 403.
4. Missing task visibility → 403.
5. System log requires `audit.view_system`; my-activity available to any internal user.
6. External auditor with valid grant can view completed task trail; active task → 403.
7. Cursor pagination shape: `{data, next_cursor, has_more}`.
8. `me` endpoint excludes `ip_address` and `user_agent`.
9. Impersonation sets `impersonated_by_public_id`.
10. Tenant isolation: events from tenant A absent in tenant B.

---

## Execution Order

1. **Migration** — create tenant `audit_events` table. Depends on: existing tenant template.
2. **Enum + Model** — `AuditEntityType` and `AuditEvent`. Depends on: 1.
3. **Domain event** — `AuditEventRecorded`. Depends on: 2.
4. **Listener + mapper registry** — `RecordAuditEvent`. Depends on: 2, and all existing domain event classes.
5. **Service** — `AuditEventService`. Depends on: 2, `TaskVisibilityScope`, `IamPolicy`, `AuditGrant`.
6. **Requests + Resource** — validation and response shaping. Depends on: 2.
7. **Controller + routes** — `AuditTrailController`, `routes/api/v1/audit.php`, require in `routes/tenant.php`. Depends on: 5, 6.
8. **Logging config** — add `audit` channel. Depends on: nothing.
9. **Feature tests** — happy path, ABAC, external auditor, pagination, impersonation, tenant isolation. Depends on: 7.
10. **Pint + tests** — `vendor/bin/pint --dirty --format agent`, then `php artisan test --filter="Modules\\Audit"`.
11. **Regenerate OpenAPI** — run Scramble export and commit `openapi/openapi.json`. Set spec contract status to `stable`.

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/tasks/{task}/audit-trail` | Sanctum + `audit.view_task` + task visibility | Cursor-paginated audit events for the task and its child entities, newest first. |
| GET | `/api/v1/audit-trail/system` | Sanctum + `audit.view_system` | Cursor-paginated system activity log. Filters: `user_id`, `event_type`, `entity_type`, `date_from`, `date_to`. |
| GET | `/api/v1/audit-trail/me` | Sanctum | Cursor-paginated activity for the current user. Same filters as system except `user_id`. Omits IP/UA. |

**Response shape (cursor paginated):**

```json
{
  "data": [
    {
      "public_id": "018...",
      "event_type": "task.created",
      "entity_type": "task",
      "entity_id": "018...",
      "root_entity_type": "task",
      "root_entity_id": "018...",
      "performed_by": { "public_id": "018...", "name_ar": "...", "name_en": "..." },
      "ip_address": "203.0.113.4",
      "user_agent": "Mozilla/5.0...",
      "impersonated_by_public_id": null,
      "payload": { "title_ar": "..." },
      "created_at": "2026-06-30T12:00:00+00:00"
    }
  ],
  "next_cursor": "eyJpZCI6MTAwfQ==",
  "has_more": true
}
```

---

## What to Test Manually

1. **Task creation audit:** Create a task → call `GET /v1/tasks/{task}/audit-trail` → verify `task.created` event appears with performer and payload.
2. **Child entity rollup:** Launch task, complete a stage, upload a document → task trail includes `stage.completed`, `document.uploaded`, etc., all with `root_entity_type=task`.
3. **ABAC deny:** User without `audit.view_task` → 403 on task trail.
4. **Confidential task:** User with `task.view.organization` but not confidential access → 403 on confidential task trail.
5. **System log filters:** Use `?event_type=task.created&date_from=2026-06-01` → only matching rows.
6. **My-activity privacy:** `GET /v1/audit-trail/me` returns current user's events and nulls `ip_address`/`user_agent`.
7. **External auditor:** Create audit grant for external auditor covering a completed task → auditor can view trail; switch to active task → 403.
8. **Impersonation:** Platform admin impersonates tenant user, creates a task → audit row has `impersonated_by_public_id` = platform admin public_id.
9. **Rate limiting:** Exceed 60 `LIST`/min → 429 with `Retry-After`.
10. **Cursor pagination:** Follow `next_cursor` through pages; verify `has_more` becomes false at end.
11. **Tenant isolation:** Events in tenant A do not appear in tenant B.
12. **Listener safety:** Force an exception inside the listener (e.g., temporarily break a mapper) → originating business action still succeeds; error is logged to `audit.log`.
13. **Append-only:** Attempt direct `AuditEvent::first()->delete()` → returns false / no deletion.
