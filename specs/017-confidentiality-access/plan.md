# Implementation Plan: 017 Confidentiality & Access

> **Spec:** `specs/017-confidentiality-access/spec.md`
> **Date:** 2026-07-01
> **Status:** `completed`

---

## Open Questions Resolved

| # | Spec Open Question | Decision | Rationale |
|---|-------------------|----------|-----------|
| 1 | Initiator-managed participants without `task.confidential.manage_participants`? | **Yes, by default.** Task initiator may add/remove confidential participants unless tenant policy disables it. | Matches government workflows where the initiator knows who needs access; tenant setting `settings.confidentiality.initiator_can_manage_participants` defaults to `true`. |
| 2 | Metadata title display — actual or redacted? | **Tenant policy decides; default redacted.** | Default redacted title (`__('confidential.redacted_title')`) is the safe option. Tenant setting `settings.confidentiality.metadata_show_actual_title` can override. |
| 3 | Governance configs apply to existing confidential tasks? | **Yes.** Governance participation is evaluated at access-time, not task-creation-time. | Configurations protect oversight for all matching tasks, not only new ones. |
| 4 | Override persistence — session, window, or single request? | **Single request in MVP.** | Simplest to audit and reason about; re-supply reason per access. `expires_at` accepted but ignored. |
| 5 | Metadata view for non-confidential tasks? | **Returns 404.** | Keeps the endpoint purpose-specific; users with normal visibility should use `GET /tasks/{task}`. |
| 6 | Participant removal history — hard delete or `removed_at`? | **Add `removed_at` timestamp.** | Preserves history while keeping the blueprint's core columns intact. |
| 7 | Governance config public identifier? | **Add `public_id` to `confidential_governance_participants` only.** | Allows standard `/api/v1/iam/confidential-governance-participants/{public_id}` URL convention. Junction and audit tables remain without `public_id`. |

---

## Technical Approach

Add three tenant DB tables (`task_confidential_participants`, `confidential_governance_participants`, `confidential_access_events`), one enum, and dedicated services/controllers for participant management, governance configuration, and confidential access (metadata + override). Centralize all classification enforcement in an updated `TaskVisibilityScope` so Task, Search, Analytics, FollowUp, Comment, Document, and Stage History reuse the same rules. Emit four domain events implementing `ShouldDispatchAfterCommit` + `ProvidesAuditData`; Audit module (Spec 015) records them automatically.

### Key Decisions

- **Classification enforcement stays in `TaskVisibilityScope`.** All read paths that already use the scope get confidential/internal rules without per-module changes.
- **Governance participant access resolved at query time.** User's current primary position is matched against active governance configs; vacant positions grant no access.
- **Confidential access events written synchronously.** Each metadata view, override, and participant change writes one row to `confidential_access_events` and fires an audit event.
- **Tenant settings drive optional behaviors.** Initiator-managed participants and redacted metadata title use `tenant()->settings['confidentiality']` with safe defaults.
- **No queue jobs.** All operations are synchronous CRUD, visibility filtering, and audit writes.

---

## Affected Modules / Files

### New Files

```
app/
├── Modules/Task/
│   ├── Enums/
│   │   └── ConfidentialAccessEventType.php
│   ├── Models/
│   │   ├── TaskConfidentialParticipant.php
│   │   └── ConfidentialAccessEvent.php
│   ├── Services/
│   │   ├── ConfidentialParticipantService.php
│   │   └── ConfidentialAccessService.php
│   ├── Controllers/
│   │   ├── ConfidentialParticipantController.php
│   │   └── ConfidentialAccessController.php
│   ├── Requests/
│   │   ├── StoreConfidentialParticipantRequest.php
│   │   ├── AccessOverrideRequest.php
│   │   └── ListConfidentialAccessEventsRequest.php
│   ├── Resources/
│   │   ├── ConfidentialParticipantResource.php
│   │   └── ConfidentialAccessEventResource.php
│   ├── Events/
│   │   ├── ConfidentialParticipantAdded.php
│   │   ├── ConfidentialParticipantRemoved.php
│   │   ├── ConfidentialMetadataViewed.php
│   │   └── ConfidentialContentOverridden.php
│   └── Exceptions/
│       ├── TaskNotConfidentialException.php
│       ├── CannotManageConfidentialParticipantsException.php
│       ├── ConfidentialAccessDeniedException.php
│       ├── DuplicateConfidentialParticipantException.php
│       └── GovernanceParticipantNotFoundException.php
├── Modules/Iam/
│   ├── Exceptions/
│   │   └── InvalidGovernanceScopeException.php
│   ├── Models/
│   │   └── ConfidentialGovernanceParticipant.php
│   ├── Services/
│   │   └── ConfidentialGovernanceParticipantService.php
│   ├── Controllers/
│   │   └── ConfidentialGovernanceParticipantController.php
│   ├── Requests/
│   │   ├── StoreConfidentialGovernanceParticipantRequest.php
│   │   └── UpdateConfidentialGovernanceParticipantRequest.php
│   ├── Resources/
│   │   └── ConfidentialGovernanceParticipantResource.php
│   └── Events/
│       ├── ConfidentialGovernanceParticipantCreated.php
│       ├── ConfidentialGovernanceParticipantUpdated.php
│       └── ConfidentialGovernanceParticipantRevoked.php
database/migrations/tenant/
├── 2026_07_02_000001_create_task_confidential_participants_table.php
├── 2026_07_02_000002_create_confidential_governance_participants_table.php
└── 2026_07_02_000003_create_confidential_access_events_table.php
tests/Feature/Modules/Task/
├── ConfidentialParticipantTest.php
├── ConfidentialAccessTest.php
└── ConfidentialVisibilityTest.php
tests/Feature/Modules/Iam/
└── ConfidentialGovernanceParticipantTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `app/Modules/Task/Scopes/TaskVisibilityScope.php` | Add `internal` restriction and full `confidential` access rules (initiator, assignees, participants, governance, admin, auditor, override not needed for scope). |
| `app/Modules/Task/Services/TaskService.php` | Refactor classification checks to use new `ConfidentialAccessService::guardCanClassify()`; no other behavior change. |
| `app/Modules/Task/Models/Task.php` | Add `confidentialParticipants()`, `confidentialAccessEvents()` relationships. |
| `app/Modules/Iam/Models/Position.php` | Add `confidentialGovernanceParticipants()` relationship (optional). |
| `app/Modules/Iam/Services/IamPolicy.php` | Add `getAuditGrantDepartmentIds()` helper so `TaskVisibilityScope` can check external-auditor grants without cross-module joins. |
| `routes/api/v1/tasks.php` | Add confidential participant, metadata, override, and access-event routes under `{task}`. |
| `routes/api/v1/iam.php` | Add confidential governance participant CRUD/revoke routes under `iam/`. |
| `bootstrap/app.php` | Register 6 new Task exceptions (all extend `DomainException`, so registration is one-liner; still list explicitly). |
| `config/logging.php` | No change — reuse existing `task` and `iam` channels. |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create `ConfidentialAccessEventType` in Task module; reuse existing `ClassificationLevel` and `ScopeType`.

**Key decisions:**
- TINYINT storage; model casts to enum.
- Form requests validate via `Rule::enum(ConfidentialAccessEventType::class)`.

**File:** `app/Modules/Task/Enums/ConfidentialAccessEventType.php`

```php
<?php

namespace App\Modules\Task\Enums;

enum ConfidentialAccessEventType: int
{
    case MetadataView = 1;
    case ContentOverride = 2;
    case ParticipantAdded = 3;
    case ParticipantRemoved = 4;

    public function auditEventType(): string
    {
        return match ($this) {
            self::MetadataView => 'confidential.metadata_viewed',
            self::ContentOverride => 'confidential.content_overridden',
            self::ParticipantAdded => 'confidential.participant_added',
            self::ParticipantRemoved => 'confidential.participant_removed',
        };
    }
}
```

**Test cases:**
1. `ConfidentialAccessEventType::MetadataView->value` → `1`
2. `ConfidentialAccessEventType::ContentOverride->auditEventType()` → `'confidential.content_overridden'`

**Rules:** `coding-standards.md` — Enum Usage. No magic numbers.

---

### 2. Migrations

**One-line summary:** Three additive tenant migrations. No `tenant_id`. Governance config gets `public_id`; junction/audit tables do not.

**Key decisions:**
- `task_confidential_participants`: add `removed_at` for history despite blueprint not listing it.
- `confidential_governance_participants`: add `public_id` (UUID v7) for URL routing; other columns match blueprint Section 4.30.
- `confidential_access_events`: no `public_id`, no soft delete.
- All FKs use `constrained()->cascadeOnDelete()` on the tenant connection.

**File:** `database/migrations/tenant/2026_07_02_000001_create_task_confidential_participants_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_confidential_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();

            $table->index(['task_id', 'user_id']);
            $table->index(['task_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_confidential_participants');
    }
};
```

**File:** `database/migrations/tenant/2026_07_02_000002_create_confidential_governance_participants_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confidential_governance_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('blueprint_category_id')->nullable()->constrained('blueprint_categories')->nullOnDelete();
            // Default 3 = ClassificationLevel::Confidential (cannot use enum in migrations)
            $table->unsignedTinyInteger('applies_to_classification_level')->default(3);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->index(['position_id', 'revoked_at']);
            $table->index(['scope_type', 'scope_department_id', 'revoked_at']);
            $table->index('blueprint_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidential_governance_participants');
    }
};
```

**File:** `database/migrations/tenant/2026_07_02_000003_create_confidential_access_events_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confidential_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('access_type');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('access_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confidential_access_events');
    }
};
```

**Rules:** `coding-standards.md` — Migrations. No `tenant_id` columns. Additive only.

---

### 3. Models

**One-line summary:** Extend `TenantModel` for governance config (needs `public_id`); use plain `Model` for junction/audit tables (no `public_id`). Add relationships on `Task` and `Position`.

**Key decisions:**
- `ConfidentialGovernanceParticipant` extends `TenantModel` to inherit `HasPublicId` UUID v7 generation.
- `TaskConfidentialParticipant` and `ConfidentialAccessEvent` extend `Model` (no `public_id`, no soft delete).
- Casts: `scope_type` → `ScopeType`, `applies_to_classification_level` → `ClassificationLevel`, `access_type` → `ConfidentialAccessEventType`.

**File:** `app/Modules/Task/Models/TaskConfidentialParticipant.php`

```php
<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskConfidentialParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id', 'user_id', 'added_by_user_id', 'added_at', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
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

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('removed_at');
    }
}
```

**File:** `app/Modules/Iam/Models/ConfidentialGovernanceParticipant.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfidentialGovernanceParticipant extends TenantModel
{
    public $timestamps = false;

    protected $fillable = [
        'public_id', 'position_id', 'scope_type', 'scope_department_id',
        'blueprint_category_id', 'applies_to_classification_level',
        'created_by_user_id', 'created_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'applies_to_classification_level' => ClassificationLevel::class,
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function blueprintCategory(): BelongsTo
    {
        return $this->belongsTo(BlueprintCategory::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
```

**File:** `app/Modules/Task/Models/ConfidentialAccessEvent.php`

```php
<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfidentialAccessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id', 'user_id', 'access_type', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'access_type' => ConfidentialAccessEventType::class,
            'created_at' => 'datetime',
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

**Update `Task.php` relationships:**

```php
public function confidentialParticipants(): HasMany
{
    return $this->hasMany(TaskConfidentialParticipant::class);
}

public function activeConfidentialParticipants(): HasMany
{
    return $this->hasMany(TaskConfidentialParticipant::class)->whereNull('removed_at');
}

public function confidentialAccessEvents(): HasMany
{
    return $this->hasMany(ConfidentialAccessEvent::class);
}
```

**Test cases:**
1. `TaskConfidentialParticipant::create([... 'removed_at' => null])->active()->exists()` → `true`
2. `ConfidentialGovernanceParticipant::create([...])->public_id` is a valid UUID string.

**Rules:** `coding-standards.md` — Models. No `tenant_id`. Use `casts()` method.

---

### 4. Exceptions

**One-line summary:** Six domain exceptions extending `App\Exceptions\DomainException`; registered in `bootstrap/app.php`.

**Key decisions:**
- All return 422 except `ConfidentialAccessDeniedException` which returns 403.
- Bilingual messages live in `lang/{en,ar}/task.php` (add keys) or use simple English fallback.

**Files:**
- `app/Modules/Task/Exceptions/TaskNotConfidentialException.php`
- `app/Modules/Task/Exceptions/CannotManageConfidentialParticipantsException.php`
- `app/Modules/Task/Exceptions/ConfidentialAccessDeniedException.php`
- `app/Modules/Task/Exceptions/DuplicateConfidentialParticipantException.php`
- `app/Modules/Task/Exceptions/InvalidGovernanceScopeException.php`
- `app/Modules/Task/Exceptions/GovernanceParticipantNotFoundException.php`

**Snippet for 403 exception:**

```php
<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class ConfidentialAccessDeniedException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct(__('task.confidential_access_denied'));
    }
}
```

**Registration in `bootstrap/app.php`:**

No individual registration needed — the base handler already catches all `DomainException` subclasses:

```php
$exceptions->renderable(fn (DomainException $e) => $e->render());
```

All six exceptions extend `DomainException`, so they are rendered by this single line. This matches the pattern used by all other spec exceptions.

**Test cases:**
1. `throw new ConfidentialAccessDeniedException` → 403 JSON `{"message":"..."}`
2. All six extend `DomainException` → `true`

**Rules:** `coding-standards.md` — Error Handling. Register in `bootstrap/app.php`.

---

### 5. Events

**One-line summary:** Four Task-side events + three IAM-side governance events. All implement `ShouldDispatchAfterCommit` and `ProvidesAuditData`.

**Key decisions:**
- Events carry the models/scalars needed for audit payload.
- Audit listener auto-records them because they implement `ProvidesAuditData`.

**Files:**
- `app/Modules/Task/Events/ConfidentialParticipantAdded.php`
- `app/Modules/Task/Events/ConfidentialParticipantRemoved.php`
- `app/Modules/Task/Events/ConfidentialMetadataViewed.php`
- `app/Modules/Task/Events/ConfidentialContentOverridden.php`
- `app/Modules/Iam/Events/ConfidentialGovernanceParticipantCreated.php`
- `app/Modules/Iam/Events/ConfidentialGovernanceParticipantUpdated.php`
- `app/Modules/Iam/Events/ConfidentialGovernanceParticipantRevoked.php`

**Snippet — `ConfidentialContentOverridden`:**

```php
<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ConfidentialContentOverridden implements ShouldDispatchAfterCommit, ProvidesAuditData
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public User $user,
        public string $reason,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: ConfidentialAccessEventType::ContentOverride->auditEventType(),
            entityType: AuditEntityType::Task,
            entityId: $this->task->id,
            entityPublicId: $this->task->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->task->id,
            rootEntityPublicId: $this->task->public_id,
            user: $this->user,
            payload: ['reason' => $this->reason],
        );
    }
}
```

**Snippet — `ConfidentialParticipantAdded`:**

```php
public function auditData(): AuditEventData
{
    return new AuditEventData(
        eventType: ConfidentialAccessEventType::ParticipantAdded->auditEventType(),
        entityType: AuditEntityType::Task,
        entityId: $this->task->id,
        entityPublicId: $this->task->public_id,
        rootEntityType: AuditEntityType::Task,
        rootEntityId: $this->task->id,
        rootEntityPublicId: $this->task->public_id,
        user: $this->addedBy,
        payload: ['participant_public_id' => $this->participant->public_id],
    );
}
```

**Test cases:**
1. Fire `ConfidentialContentOverridden` → one `audit_events` row with `event_type='confidential.content_overridden'` and `payload.reason`.
2. Fire `ConfidentialParticipantAdded` → audit row has `root_entity_type=Task`.

**Rules:** `coding-standards.md` — Domain Events (`ShouldDispatchAfterCommit`); Audit module consumes `ProvidesAuditData`.

---

### 6. Updated `TaskVisibilityScope`

**One-line summary:** Apply classification rules **after** normal ABAC rules: `public` unchanged, `internal` blocks lateral uninvolved visibility, `confidential` restricts to explicit allowed relationships.

**Key decisions:**
- Keep existing ABAC base query; wrap with classification filter.
- Confidential allowed list: tenant admin, initiator, current/past assignee, active named participant, governance participant, external auditor with grant.
- Organization-wide capability does **not** bypass confidential.
- `task.confidential.view_metadata` also does **not** bypass visibility scope; metadata endpoint is separate.
- For governance scope, match user's current primary position against active configs; `scope_department_id` matches any task-touched department via `stage_instances.owning_department_id`.
- For category matching, join to `blueprints` is required; this is a pragmatic exception to strict module-boundary joins. <!-- TODO: verify — alternative is to denormalize `blueprint_category_id` onto tasks. -->

**File:** `app/Modules/Task/Scopes/TaskVisibilityScope.php`

```php
<?php

namespace App\Modules\Task\Scopes;

use App\Enums\AccountType;
use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\ConfidentialGovernanceParticipantService;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class TaskVisibilityScope
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private ConfidentialGovernanceParticipantService $governanceService,
    ) {}

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function apply(Builder $query, User $user): Builder
    {
        $this->applyBaseVisibility($query, $user);

        return $this->applyClassificationFilter($query, $user);
    }

    private function applyBaseVisibility(Builder $query, User $user): void
    {
        if ($this->iamPolicy->hasCapability($user, 'task.view.organization')) {
            return;
        }

        $userDeptId = $user->currentPositionAssignment?->position?->department_id;

        $query->where(function (Builder $q) use ($user, $userDeptId) {
            $q->where('initiator_user_id', $user->id);

            $q->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));

            $q->orWhereHas('activeConfidentialParticipants', fn (Builder $pq) => $pq->where('user_id', $user->id));

            if ($this->iamPolicy->hasCapability($user, 'task.view.department_touched') && $userDeptId) {
                $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->where('owning_department_id', $userDeptId));
            }

            if ($this->iamPolicy->hasCapability($user, 'task.view.follow_up_scope')) {
                $monitoredDeptIds = $user->monitoringScopeGrants()
                    ->where('scope_type', ScopeType::SPECIFIC_DEPARTMENT)
                    ->whereNull('revoked_at')
                    ->pluck('scope_department_id');

                if ($monitoredDeptIds->isNotEmpty()) {
                    $q->orWhereHas('stageInstances', fn (Builder $sq) => $sq->whereIn('owning_department_id', $monitoredDeptIds));
                }
            }

            $this->applyGovernanceBaseVisibility($q, $user);
        });
    }

    private function applyGovernanceBaseVisibility(Builder $query, User $user): void
    {
        $primaryPositionId = $user->currentPositionAssignment?->position_id;

        if (! $primaryPositionId) {
            return;
        }

        $allConfigs = $this->governanceService->allActive();
        $configs = collect($allConfigs)->filter(fn ($c) => $c->position_id === $primaryPositionId
            && $c->applies_to_classification_level === ClassificationLevel::Confidential);

        if ($configs->isEmpty()) {
            return;
        }

        $query->orWhere(function (Builder $gov) use ($configs) {
            $gov->where('classification_level', ClassificationLevel::Confidential->value);

            $hasTenantScope = $configs->contains(fn ($c) => $c->scope_type === ScopeType::TENANT);
            $departmentIds = $configs->whereIn('scope_type', [ScopeType::SPECIFIC_DEPARTMENT, ScopeType::DEPARTMENT_TREE])
                ->pluck('scope_department_id')->filter()->unique()->values()->all();
            $categoryIds = $configs->pluck('blueprint_category_id')->filter()->unique()->values()->all();

            if ($hasTenantScope && empty($categoryIds)) {
                $gov->whereRaw('1 = 1');

                return;
            }

            if (! $hasTenantScope && ! empty($departmentIds)) {
                $gov->whereHas('stageInstances', fn (Builder $sq) => $sq->whereIn('owning_department_id', $departmentIds));
            }

            if (! empty($categoryIds)) {
                $gov->whereHas('blueprint', fn (Builder $bq) => $bq->whereIn('category_id', $categoryIds));
            }
        });
    }

    private function applyClassificationFilter(Builder $query, User $user): Builder
    {
        if ($user->isTenantAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Public tasks: already filtered by base visibility.
            $q->where('classification_level', ClassificationLevel::Public->value);

            // Internal tasks: allow if user has org-wide, is participant, or task touched allowed scope.
            $q->orWhere(function (Builder $internal) use ($user) {
                $internal->where('classification_level', ClassificationLevel::Internal->value);

                if (! $this->iamPolicy->hasCapability($user, 'task.view.organization')) {
                    $internal->where(function (Builder $allow) use ($user) {
                        $allow->where('initiator_user_id', $user->id)
                            ->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));

                        $userDeptId = $user->currentPositionAssignment?->position?->department_id;
                        if ($userDeptId) {
                            $allow->orWhereHas('stageInstances', fn (Builder $sq) => $sq->where('owning_department_id', $userDeptId));
                        }
                    });
                }
            });

            // Confidential tasks: strict allow list.
            $q->orWhere(function (Builder $conf) use ($user) {
                $conf->where('classification_level', ClassificationLevel::Confidential->value)
                    ->where(function (Builder $allow) use ($user) {
                        $allow->where('initiator_user_id', $user->id)
                            ->orWhereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id))
                            ->orWhereHas('activeConfidentialParticipants', fn (Builder $pq) => $pq->where('user_id', $user->id));

                        $this->applyGovernanceAccess($allow, $user);
                        $this->applyExternalAuditorAccess($allow, $user);
                    });
            });
        });
    }

    private function applyGovernanceAccess(Builder $query, User $user): void
    {
        $primaryPositionId = $user->currentPositionAssignment?->position_id;

        if (! $primaryPositionId) {
            return;
        }

        $allConfigs = $this->governanceService->allActive();
        $configs = collect($allConfigs)->filter(fn ($c) => $c->position_id === $primaryPositionId
            && $c->applies_to_classification_level === ClassificationLevel::Confidential);

        if ($configs->isEmpty()) {
            return;
        }

        $hasTenantScope = $configs->contains(fn ($c) => $c->scope_type === ScopeType::TENANT);
        $departmentIds = $configs->whereIn('scope_type', [ScopeType::SPECIFIC_DEPARTMENT, ScopeType::DEPARTMENT_TREE])
            ->pluck('scope_department_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $categoryIds = $configs->pluck('blueprint_category_id')->filter()->unique()->values()->all();

        $query->orWhere(function (Builder $governance) use ($hasTenantScope, $departmentIds, $categoryIds) {
            if ($hasTenantScope && empty($categoryIds)) {
                $governance->whereRaw('1 = 1');

                return;
            }

            if (! $hasTenantScope && ! empty($departmentIds)) {
                $governance->whereHas('stageInstances', function (Builder $sq) use ($departmentIds) {
                    $sq->whereIn('owning_department_id', $departmentIds);
                });
            }

            if (! empty($categoryIds)) {
                $governance->whereHas('blueprint', function (Builder $bq) use ($categoryIds) {
                    $bq->whereIn('category_id', $categoryIds);
                });
            }
        });
    }

    private function applyExternalAuditorAccess(Builder $query, User $user): void
    {
        if ($user->account_type !== AccountType::EXTERNAL_AUDITOR) {
            return;
        }

        $grantDeptIds = $this->iamPolicy->getAuditGrantDepartmentIds($user);

        // No active grant → no visibility.
        if ($grantDeptIds === []) {
            return;
        }

        // Tenant-wide grant → all completed/cancelled/archived tasks visible.
        if ($grantDeptIds === null) {
            $query->orWhere(function (Builder $auditor) {
                $auditor->whereIn('status', [\App\Modules\Task\Enums\TaskStatus::Completed->value, \App\Modules\Task\Enums\TaskStatus::Cancelled->value])
                    ->orWhereNotNull('archived_at');
            });

            return;
        }

        // Department-scoped grant → task must have touched one of the granted departments.
        $query->orWhere(function (Builder $auditor) use ($grantDeptIds) {
            $auditor->where(function (Builder $statusQ) {
                $statusQ->whereIn('status', [\App\Modules\Task\Enums\TaskStatus::Completed->value, \App\Modules\Task\Enums\TaskStatus::Cancelled->value])
                    ->orWhereNotNull('archived_at');
            })->whereHas('stageInstances', function (Builder $sq) use ($grantDeptIds) {
                $sq->whereIn('owning_department_id', $grantDeptIds);
            });
        });
    }
}
```

**Important notes on governance scope:**
- Governance configs are loaded via `ConfidentialGovernanceParticipantService::allActive()` which uses the cache key `{tenant_slug}:iam:confidential_governance_participants:all` (TTL 300s). Cache is invalidated on create/update/revoke.
- When governance config has tenant-wide scope with no category filter, the query uses `whereRaw('1 = 1')` to ensure the OR clause produces a match.
- Base visibility scope includes `activeConfidentialParticipants` and `applyGovernanceBaseVisibility()` so named and governance participants pass the base filter before classification rules apply.
- The above uses `stageInstances.owning_department_id` for department matching and `blueprint.category_id` for category matching. If the project wants stricter module isolation, add `blueprint_category_id` to `tasks` as a denormalized column and remove the Blueprint join. <!-- TODO: verify -->

**Helper to add to `IamPolicy`:**

```php
/**
 * @return array<int>|null  null = tenant-wide grant; [] = no grant; array = department IDs.
 */
public function getAuditGrantDepartmentIds(User $auditor): ?array
{
    $grants = \App\Modules\Iam\Models\AuditGrant::where('external_auditor_user_id', $auditor->id)
        ->whereNull('revoked_at')
        ->where('date_range_start', '<=', now())
        ->where('date_range_end', '>=', now())
        ->get(['department_id']);

    if ($grants->contains(fn ($g) => $g->department_id === null)) {
        return null;
    }

    return $grants->pluck('department_id')->filter()->unique()->values()->all();
}
```

**Test cases:**
1. User with `task.view.organization` lists tasks → sees public/internal but **not** confidential tasks they are not authorized for.
2. Confidential task initiated by user A → user A sees it; user B with org-wide visibility does **not** see it.
3. Named participant added to confidential task → that participant sees it in list.
4. Internal task from department X → user in department Y with only `task.view.department_touched` does not see it; user in department X does.

**Rules:** `coding-standards.md` — Performance (inline WHERE, no post-query filtering), Module Boundaries (note the Blueprint join caveat).

---

### 7. Confidential Participant Service

**One-line summary:** Add/remove/list named confidential participants with duplicate checks, initiator fallback, and audit event writes.

**Key decisions:**
- Add/remove wrapped in `DB::transaction()` (writes participant row + access event row + fires event).
- Authorization: `task.confidential.manage_participants` scoped to task's department, **or** initiator when tenant setting allows.
- Duplicate active participant rejected with 422.
- Removal sets `removed_at`; does not hard-delete.

**File:** `app/Modules/Task/Services/ConfidentialParticipantService.php`

```php
<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Events\ConfidentialParticipantAdded;
use App\Modules\Task\Events\ConfidentialParticipantRemoved;
use App\Modules\Task\Exceptions\CannotManageConfidentialParticipantsException;
use App\Modules\Task\Exceptions\DuplicateConfidentialParticipantException;
use App\Modules\Task\Exceptions\TaskNotConfidentialException;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskConfidentialParticipant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialParticipantService
{
    public function __construct(
        private IamPolicy $iamPolicy,
    ) {}

    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        try {
            return TaskConfidentialParticipant::where('task_id', $task->id)
                ->with(['user', 'addedBy'])
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to list confidential participants', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.list',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function add(Task $task, User $participant, User $addedBy): TaskConfidentialParticipant
    {
        try {
            return DB::transaction(function () use ($task, $participant, $addedBy) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if (! $this->canManageParticipants($task, $addedBy)) {
                    throw new CannotManageConfidentialParticipantsException;
                }

                $exists = TaskConfidentialParticipant::where('task_id', $task->id)
                    ->where('user_id', $participant->id)
                    ->whereNull('removed_at')
                    ->exists();

                if ($exists) {
                    throw new DuplicateConfidentialParticipantException;
                }

                $row = TaskConfidentialParticipant::create([
                    'task_id' => $task->id,
                    'user_id' => $participant->id,
                    'added_by_user_id' => $addedBy->id,
                    'added_at' => now(),
                ]);

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $addedBy->id,
                    'access_type' => ConfidentialAccessEventType::ParticipantAdded,
                ]);

                event(new ConfidentialParticipantAdded($task, $participant, $addedBy));

                return $row->load(['user', 'addedBy']);
            });
        } catch (TaskNotConfidentialException|CannotManageConfidentialParticipantsException|DuplicateConfidentialParticipantException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to add confidential participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.add',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $addedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function remove(Task $task, User $participant, User $removedBy): TaskConfidentialParticipant
    {
        try {
            return DB::transaction(function () use ($task, $participant, $removedBy) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if (! $this->canManageParticipants($task, $removedBy)) {
                    throw new CannotManageConfidentialParticipantsException;
                }

                $row = TaskConfidentialParticipant::where('task_id', $task->id)
                    ->where('user_id', $participant->id)
                    ->whereNull('removed_at')
                    ->first();

                if (! $row) {
                    throw new GovernanceParticipantNotFoundException; // 404: participant not found
                }

                $row->update(['removed_at' => now()]);

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $removedBy->id,
                    'access_type' => ConfidentialAccessEventType::ParticipantRemoved,
                ]);

                event(new ConfidentialParticipantRemoved($task, $participant, $removedBy));

                return $row->load(['user', 'addedBy']);
            });
        } catch (CannotManageConfidentialParticipantsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to remove confidential participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_participant.remove',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $removedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function canManageParticipants(Task $task, User $user): bool
    {
        if ($task->initiator_user_id === $user->id) {
            $settings = tenant()?->settings['confidentiality'] ?? [];
            if ($settings['initiator_can_manage_participants'] ?? true) {
                return true;
            }
        }

        if ($this->iamPolicy->check($user, 'task.confidential.manage_participants', \App\Enums\ScopeType::TENANT)) {
            return true;
        }

        $taskDeptId = $task->stageInstances()->first()?->owning_department_id
            ?? $task->initiator?->currentPositionAssignment?->position?->department_id;

        if ($taskDeptId === null) {
            return false;
        }

        return $this->iamPolicy->check($user, 'task.confidential.manage_participants', \App\Enums\ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId);
    }
}
```

**Test cases:**
1. Initiator adds user to confidential task → row created, `confidential_access_events` has `ParticipantAdded`, audit event recorded.
2. Non-initiator without `task.confidential.manage_participants` tries to add → 403/422.
3. Adding same user twice → 422 duplicate.

**Rules:** `coding-standards.md` — Database Transactions, Error Handling (`Log::channel('task')`), Enums.

---

### 8. Confidential Governance Participant Service

**One-line summary:** CRUD + revoke for automatic governance participants; cached config list; scope validation.

**Key decisions:**
- Validate scope rules from blueprint Section 4.30 / spec: `tenant` requires no department; `specific_department`/`department_tree` require `scope_department_id`; `own_department`/`own_tasks` invalid.
- Cache key `{tenant_slug}:iam:confidential_governance_participants:all` TTL 300s; invalidated on create/update/revoke.
- `applies_to_classification_level` defaults to `ClassificationLevel::Confidential`.

**File:** `app/Modules/Iam/Services/ConfidentialGovernanceParticipantService.php`

```php
<?php

namespace App\Modules\Iam\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantCreated;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantRevoked;
use App\Modules\Iam\Events\ConfidentialGovernanceParticipantUpdated;
use App\Modules\Iam\Exceptions\InvalidGovernanceScopeException;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialGovernanceParticipantService
{
    private const CACHE_KEY = 'iam:confidential_governance_participants:all';

    public function list(int $perPage = 15): CursorPaginator
    {
        try {
            return ConfidentialGovernanceParticipant::with(['position', 'scopeDepartment', 'blueprintCategory', 'createdBy'])
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to list confidential governance participants', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.list',
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function allActive(): array
    {
        $key = $this->cacheKey();

        return Cache::remember($key, 300, function () {
            return ConfidentialGovernanceParticipant::with('position')
                ->whereNull('revoked_at')
                ->get()
                ->all();
        });
    }

    public function create(array $data, User $createdBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $this->validateScope($data);

                $position = Position::where('public_id', $data['position_id'])->firstOrFail();
                $scopeDepartmentId = null;
                if (! empty($data['scope_department_id'])) {
                    $scopeDepartmentId = Department::where('public_id', $data['scope_department_id'])->value('id');
                }

                $config = ConfidentialGovernanceParticipant::create([
                    'position_id' => $position->id,
                    'scope_type' => $data['scope_type'],
                    'scope_department_id' => $scopeDepartmentId,
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? null,
                    'applies_to_classification_level' => $data['applies_to_classification_level'] ?? \App\Modules\Task\Enums\ClassificationLevel::Confidential->value,
                    'created_by_user_id' => $createdBy->id,
                    'created_at' => now(),
                ]);

                $config->load(['position', 'scopeDepartment', 'blueprintCategory']);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantCreated($config));

                return $config;
            });
        } catch (InvalidGovernanceScopeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to create confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.create',
                'performed_by' => $createdBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(ConfidentialGovernanceParticipant $config, array $data, User $updatedBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($config, $data, $updatedBy) {
                $this->validateScope($data);

                $update = [
                    'scope_type' => $data['scope_type'] ?? $config->scope_type,
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? $config->blueprint_category_id,
                    'applies_to_classification_level' => $data['applies_to_classification_level'] ?? $config->applies_to_classification_level->value,
                ];

                if (array_key_exists('scope_department_id', $data)) {
                    $update['scope_department_id'] = empty($data['scope_department_id'])
                        ? null
                        : Department::where('public_id', $data['scope_department_id'])->value('id');
                }

                $config->update($update);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantUpdated($config->fresh(), $updatedBy));

                return $config->fresh(['position', 'scopeDepartment', 'blueprintCategory']);
            });
        } catch (InvalidGovernanceScopeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to update confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.update',
                'entity_id' => $config->public_id,
                'performed_by' => $updatedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revoke(ConfidentialGovernanceParticipant $config, User $revokedBy): ConfidentialGovernanceParticipant
    {
        try {
            return DB::transaction(function () use ($config, $revokedBy) {
                $config->update(['revoked_at' => now()]);
                $this->clearCache();
                event(new ConfidentialGovernanceParticipantRevoked($config, $revokedBy));

                return $config->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke confidential governance participant', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential_governance_participant.revoke',
                'entity_id' => $config->public_id,
                'performed_by' => $revokedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateScope(array $data): void
    {
        $scopeType = is_object($data['scope_type']) ? $data['scope_type']->value : (int) $data['scope_type'];

        if (in_array($scopeType, [ScopeType::OWN_DEPARTMENT->value, ScopeType::OWN_TASKS->value], true)) {
            throw new InvalidGovernanceScopeException;
        }

        $needsDepartment = in_array($scopeType, [ScopeType::SPECIFIC_DEPARTMENT->value, ScopeType::DEPARTMENT_TREE->value], true);
        if ($needsDepartment && empty($data['scope_department_id'])) {
            throw new InvalidGovernanceScopeException;
        }

        if ($scopeType === ScopeType::TENANT->value && ! empty($data['scope_department_id'])) {
            throw new InvalidGovernanceScopeException;
        }
    }

    private function cacheKey(): string
    {
        return tenant()?->slug.':'.self::CACHE_KEY;
    }

    private function clearCache(): void
    {
        Cache::forget($this->cacheKey());
    }
}
```

**Test cases:**
1. Create governance config with `scope_type=tenant` and no department → success.
2. Create config with `scope_type=specific_department` but missing `scope_department_id` → 422.
3. Revoke config → cache cleared, `revoked_at` set.

**Rules:** `coding-standards.md` — Caching (tenant-prefixed, 300s TTL, invalidation on events), Transactions, Logging (`iam` channel), Enums.

---

### 9. Confidential Access Service

**One-line summary:** Metadata view and content override with capability checks, reason validation, and audit writes.

**Key decisions:**
- `metadata()` returns 404 if caller already has full visibility to the task.
- `override()` returns full `TaskResource` payload and records access event.
- Both operations wrapped in `DB::transaction()`.
- Metadata title is redacted by default; tenant setting can reveal actual title.

**File:** `app/Modules/Task/Services/ConfidentialAccessService.php`

```php
<?php

namespace App\Modules\Task\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Enums\ClassificationLevel;
use App\Modules\Task\Enums\ConfidentialAccessEventType;
use App\Modules\Task\Events\ConfidentialContentOverridden;
use App\Modules\Task\Events\ConfidentialMetadataViewed;
use App\Modules\Task\Exceptions\ConfidentialAccessDeniedException;
use App\Modules\Task\Exceptions\TaskNotConfidentialException;
use App\Modules\Task\Models\ConfidentialAccessEvent;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfidentialAccessService
{
    public function __construct(
        private IamPolicy $iamPolicy,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function metadata(Task $task, User $user): array
    {
        try {
            return DB::transaction(function () use ($task, $user) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                if ($this->hasFullVisibility($task, $user)) {
                    abort(404, 'Use the normal task endpoint.');
                }

                $taskDeptId = $this->taskDepartmentId($task);
                if (! $this->iamPolicy->check($user, 'task.confidential.view_metadata', ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId)
                    && ! $this->iamPolicy->check($user, 'task.confidential.view_metadata', ScopeType::TENANT)) {
                    throw new ConfidentialAccessDeniedException;
                }

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'access_type' => ConfidentialAccessEventType::MetadataView,
                ]);

                event(new ConfidentialMetadataViewed($task, $user));

                $settings = tenant()?->settings['confidentiality'] ?? [];
                $showActualTitle = $settings['metadata_show_actual_title'] ?? false;

                return [
                    'public_id' => $task->public_id,
                    'classification_level' => $task->classification_level,
                    'title' => $showActualTitle
                        ? ($task->title_en ?? $task->title_ar)
                        : __('task.confidential_redacted_title'),
                    'owning_department' => $task->stageInstances()->first()?->owningDepartment?->only('public_id', 'name_ar', 'name_en'),
                    'current_responsible_position' => $task->stageInstances()->first()?->assignments()->first()?->position?->only('public_id', 'title_ar', 'title_en'),
                    'status' => $task->status,
                    'due_date' => $task->due_date?->toDateString(),
                    'sla_health' => null, // populated by Tracking module if needed; keep null in MVP
                    'metadata_only' => true,
                ];
            });
        } catch (TaskNotConfidentialException|ConfidentialAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to view confidential metadata', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential.metadata_view',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function override(Task $task, string $reason, User $user): Task
    {
        try {
            return DB::transaction(function () use ($task, $reason, $user) {
                if ($task->classification_level !== ClassificationLevel::Confidential) {
                    throw new TaskNotConfidentialException;
                }

                $taskDeptId = $this->taskDepartmentId($task);
                if (! $this->iamPolicy->check($user, 'task.confidential.view_override', ScopeType::SPECIFIC_DEPARTMENT, $taskDeptId)
                    && ! $this->iamPolicy->check($user, 'task.confidential.view_override', ScopeType::TENANT)) {
                    throw new ConfidentialAccessDeniedException;
                }

                ConfidentialAccessEvent::create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'access_type' => ConfidentialAccessEventType::ContentOverride,
                    'reason' => $reason,
                ]);

                event(new ConfidentialContentOverridden($task, $user, $reason));

                return $task->load([
                    'priority', 'blueprint.category', 'initiator',
                    'stageInstances.assignments.user',
                    'stageInstances.subStageInstances',
                ]);
            });
        } catch (TaskNotConfidentialException|ConfidentialAccessDeniedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to override confidential access', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'confidential.access_override',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function guardCanClassify(User $user): void
    {
        if (! $this->iamPolicy->hasCapability($user, 'task.classify.confidential')) {
            abort(403, 'Missing task.classify.confidential capability.');
        }
    }

    private function hasFullVisibility(Task $task, User $user): bool
    {
        return $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();
    }

    private function taskDepartmentId(Task $task): ?int
    {
        return $task->stageInstances()->first()?->owning_department_id
            ?? $task->initiator?->currentPositionAssignment?->position?->department_id;
    }
}
```

**Test cases:**
1. User with `task.confidential.view_metadata` views metadata of confidential task they cannot otherwise see → returns redacted metadata + audit row.
2. Same user with full visibility calls metadata → 404.
3. User with `task.confidential.view_override` and reason → returns full task + audit row with reason.
4. Override on non-confidential task → 422.

**Rules:** `coding-standards.md` — Transactions, Logging (`task` channel), Enums, Caching (none for access events).

---

### 10. Controllers

**One-line summary:** Thin controllers validate, rate-limit, and delegate. Follow `TaskExternalReferenceController` and `MonitoringScopeGrantController` patterns.

**Key decisions:**
- `HasRateLimiting` on all controllers.
- `LIST` on GET endpoints; `MUTATE` on POST/PUT/DELETE.
- Participant/list endpoints guard task visibility via `TaskVisibilityScope`.
- Governance controller uses `capability:iam.manage_capabilities` route middleware.

**File:** `app/Modules/Task/Controllers/ConfidentialParticipantController.php`

```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\StoreConfidentialParticipantRequest;
use App\Modules\Task\Resources\ConfidentialParticipantResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\ConfidentialParticipantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfidentialParticipantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialParticipantService $participantService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->participantService->listForTask($task, $request->integer('per_page', 15))
            ->through(fn ($row) => new ConfidentialParticipantResource($row));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreConfidentialParticipantRequest $request, Task $task): ConfidentialParticipantResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $participant = User::where('public_id', $request->validated('user_id'))->firstOrFail();
        $row = $this->participantService->add($task, $participant, $user);

        return new ConfidentialParticipantResource($row);
    }

    public function destroy(Request $request, Task $task, User $user): JsonResponse
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->participantService->remove($task, $user, $request->user());

        return response()->json(null, 204);
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            throw new \App\Modules\Task\Exceptions\ConfidentialAccessDeniedException;
        }
    }
}
```

**File:** `app/Modules/Task/Controllers/ConfidentialAccessController.php`

```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\AccessOverrideRequest;
use App\Modules\Task\Resources\ConfidentialAccessEventResource;
use App\Modules\Task\Resources\TaskDetailResource;
use App\Modules\Task\Services\ConfidentialAccessService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class ConfidentialAccessController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialAccessService $accessService,
    ) {}

    public function metadata(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $metadata = $this->accessService->metadata($task, $request->user());

        return response()->json($metadata);
    }

    public function override(AccessOverrideRequest $request, Task $task): TaskDetailResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $task = $this->accessService->override($task, $request->validated('reason'), $request->user());

        return new TaskDetailResource($task);
    }

    public function events(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = \App\Modules\Task\Models\ConfidentialAccessEvent::where('task_id', $task->id)
            ->with('user')
            ->orderBy('id')
            ->cursorPaginate($request->integer('per_page', 15))
            ->through(fn ($event) => new ConfidentialAccessEventResource($event));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
```

**File:** `app/Modules/Iam/Controllers/ConfidentialGovernanceParticipantController.php`

```php
<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Models\ConfidentialGovernanceParticipant;
use App\Modules\Iam\Requests\StoreConfidentialGovernanceParticipantRequest;
use App\Modules\Iam\Requests\UpdateConfidentialGovernanceParticipantRequest;
use App\Modules\Iam\Resources\ConfidentialGovernanceParticipantResource;
use App\Modules\Iam\Services\ConfidentialGovernanceParticipantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfidentialGovernanceParticipantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ConfidentialGovernanceParticipantService $governanceService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->governanceService->list($request->integer('per_page', 15))
            ->through(fn ($config) => new ConfidentialGovernanceParticipantResource($config));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreConfidentialGovernanceParticipantRequest $request): ConfidentialGovernanceParticipantResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $config = $this->governanceService->create($request->validated(), $request->user());

        return new ConfidentialGovernanceParticipantResource($config);
    }

    public function update(UpdateConfidentialGovernanceParticipantRequest $request, ConfidentialGovernanceParticipant $participant): ConfidentialGovernanceParticipantResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $config = $this->governanceService->update($participant, $request->validated(), $request->user());

        return new ConfidentialGovernanceParticipantResource($config);
    }

    public function revoke(Request $request, ConfidentialGovernanceParticipant $participant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->governanceService->revoke($participant, $request->user());

        return response()->json(new ConfidentialGovernanceParticipantResource($participant->fresh()));
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin, no business logic), Rate Limiting (`HasRateLimiting` trait).

---

### 11. Requests

**One-line summary:** Dedicated Form Request classes for all mutating endpoints. `authorize()` returns `true`; ABAC handled in service.

**Files:**
- `app/Modules/Task/Requests/StoreConfidentialParticipantRequest.php`
- `app/Modules/Task/Requests/AccessOverrideRequest.php`
- `app/Modules/Task/Requests/ListConfidentialAccessEventsRequest.php`
- `app/Modules/Iam/Requests/StoreConfidentialGovernanceParticipantRequest.php`
- `app/Modules/Iam/Requests/UpdateConfidentialGovernanceParticipantRequest.php`

**Snippet — `StoreConfidentialGovernanceParticipantRequest`:**

```php
<?php

namespace App\Modules\Iam\Requests;

use App\Enums\ScopeType;
use App\Modules\Task\Enums\ClassificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConfidentialGovernanceParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'string', 'uuid', 'exists:positions,public_id'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'scope_department_id' => ['nullable', 'string', 'uuid', 'exists:departments,public_id'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid', 'exists:blueprint_categories,public_id'],
            'applies_to_classification_level' => ['nullable', Rule::enum(ClassificationLevel::class)],
        ];
    }
}
```

**Snippet — `AccessOverrideRequest`:**

```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccessOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'], // accepted but not enforced in MVP
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation (Form Requests), Enum Usage (`Rule::enum`).

---

### 12. Resources

**One-line summary:** Transform models to JSON. Expose `public_id` only. Bilingual fallback.

**Files:**
- `app/Modules/Task/Resources/ConfidentialParticipantResource.php`
- `app/Modules/Task/Resources/ConfidentialAccessEventResource.php`
- `app/Modules/Iam/Resources/ConfidentialGovernanceParticipantResource.php`

**Snippet — `ConfidentialAccessEventResource`:**

```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidentialAccessEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en ?? $this->user->name_ar,
            ],
            'access_type' => $this->access_type->auditEventType(),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`access_type` returns the readable event type string (e.g. `'confidential.content_overridden'`), not the raw TINYINT.

**Snippet — `ConfidentialParticipantResource`:**

```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfidentialParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'public_id' => $this->user->public_id,
                'name_ar' => $this->user->name_ar,
                'name_en' => $this->user->name_en ?? $this->user->name_ar,
            ],
            'added_by' => [
                'public_id' => $this->addedBy->public_id,
                'name_ar' => $this->addedBy->name_ar,
                'name_en' => $this->addedBy->name_en ?? $this->addedBy->name_ar,
            ],
            'added_at' => $this->added_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources (`public_id` only), N+1 prevention (eager-load in service).

---

### 13. Routes

**One-line summary:** Append task-side routes under `{task}`; add IAM routes under `iam/`.

**File:** `routes/api/v1/tasks.php` (add before closing `});`)

```php
// Confidential participants
Route::get('{task}/confidential-participants', [\App\Modules\Task\Controllers\ConfidentialParticipantController::class, 'index']);
Route::post('{task}/confidential-participants', [\App\Modules\Task\Controllers\ConfidentialParticipantController::class, 'store']);
Route::delete('{task}/confidential-participants/{user}', [\App\Modules\Task\Controllers\ConfidentialParticipantController::class, 'destroy']);

// Confidential access
Route::get('{task}/metadata', [\App\Modules\Task\Controllers\ConfidentialAccessController::class, 'metadata']);
Route::post('{task}/access-override', [\App\Modules\Task\Controllers\ConfidentialAccessController::class, 'override']);
Route::get('{task}/confidential-access-events', [\App\Modules\Task\Controllers\ConfidentialAccessController::class, 'events']);
```

**File:** `routes/api/v1/iam.php` (add inside authenticated group)

```php
Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
    Route::get('confidential-governance-participants', [\App\Modules\Iam\Controllers\ConfidentialGovernanceParticipantController::class, 'index']);
    Route::post('confidential-governance-participants', [\App\Modules\Iam\Controllers\ConfidentialGovernanceParticipantController::class, 'store']);
    Route::put('confidential-governance-participants/{participant}', [\App\Modules\Iam\Controllers\ConfidentialGovernanceParticipantController::class, 'update']);
    Route::post('confidential-governance-participants/{participant}/revoke', [\App\Modules\Iam\Controllers\ConfidentialGovernanceParticipantController::class, 'revoke']);
});
```

**Rules:** `coding-standards.md` — Routes under `/api/v1/`; kebab-case; capability middleware for mutations.

---

### 14. Refactor `TaskService` Classification Checks

**One-line summary:** Replace inline capability checks with `ConfidentialAccessService::guardCanClassify()` for consistency.

**File:** `app/Modules/Task/Services/TaskService.php`

**Changes:**
1. Inject `ConfidentialAccessService` into constructor.
2. Replace:
   ```php
   if (! $this->iamPolicy->hasCapability($user, 'task.classify.confidential')) {
       abort(403, 'Missing task.classify.confidential capability.');
   }
   ```
   with:
   ```php
   $this->confidentialAccessService->guardCanClassify($user);
   ```
   in both `create()` and `update()`.

**Rules:** `coding-standards.md` — Services (single responsibility; reuse logic).

---

### 15. Tests

**One-line summary:** Feature tests for all new endpoints and classification enforcement across modules.

**Files:**
- `tests/Feature/Modules/Task/ConfidentialParticipantTest.php`
- `tests/Feature/Modules/Task/ConfidentialAccessTest.php`
- `tests/Feature/Modules/Task/ConfidentialVisibilityTest.php`
- `tests/Feature/Modules/Iam/ConfidentialGovernanceParticipantTest.php`

**Minimum test cases:**
1. Create confidential task → non-participant with `task.view.organization` cannot see it in list.
2. Add named participant → participant sees task; removal revokes visibility.
3. Governance participant config → current occupant sees matching confidential tasks.
4. Metadata view → returns redacted metadata + audit event; full-visibility user gets 404.
5. Content override → returns full task + audit event with reason.
6. Invalid governance scope combinations → 422.
7. Tenant isolation → config/participants/events isolated per tenant.
8. Rate limiting → 429 after 30 mutations / 60 lists per minute.

**Rules:** `testing-policy.md` — Feature tests mandatory; happy path + auth + validation + tenant isolation.

---

## Execution Order

1. **Enum** — `ConfidentialAccessEventType`.
2. **Migrations** — create three tenant tables in dependency order.
3. **Models** — `TaskConfidentialParticipant`, `ConfidentialGovernanceParticipant`, `ConfidentialAccessEvent`; update `Task` relationships.
4. **Exceptions** — six new classes; register in `bootstrap/app.php`.
5. **Events** — four Task + three IAM events implementing `ProvidesAuditData`.
6. **Services** — `ConfidentialParticipantService`, `ConfidentialGovernanceParticipantService`, `ConfidentialAccessService`.
7. **Updated `TaskVisibilityScope`** — integrate classification rules; depends on models/events.
8. **Refactor `TaskService`** — use `ConfidentialAccessService::guardCanClassify()`.
9. **Requests + Resources** — validation and response shaping.
10. **Controllers** — participant, access, governance.
11. **Routes** — append to `tasks.php` and `iam.php`.
12. **Feature tests** — all new behavior + cross-module visibility.
13. **Run Pint** — `vendor/bin/pint --dirty --format agent`.
14. **Run tests** — `php artisan test --compact --filter="Confidential"`.
15. **Regenerate OpenAPI** — commit `openapi/openapi.json`; set spec contract status to `stable`.

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/tasks/{task}/confidential-participants` | Sanctum | task visibility | Cursor-paginated named participants. |
| POST | `/api/v1/tasks/{task}/confidential-participants` | Sanctum | `task.confidential.manage_participants` or initiator | Add named participant. |
| DELETE | `/api/v1/tasks/{task}/confidential-participants/{user}` | Sanctum | `task.confidential.manage_participants` or initiator | Soft-remove participant (`removed_at`). |
| GET | `/api/v1/tasks/{task}/metadata` | Sanctum | `task.confidential.view_metadata` | Redacted metadata for non-visible confidential tasks. |
| POST | `/api/v1/tasks/{task}/access-override` | Sanctum | `task.confidential.view_override` | Open full confidential content with audited reason. |
| GET | `/api/v1/tasks/{task}/confidential-access-events` | Sanctum | task visibility | Cursor-paginated confidential access log for the task. |
| GET | `/api/v1/iam/confidential-governance-participants` | Sanctum | `iam.manage_capabilities` | Cursor-paginated governance configs. |
| POST | `/api/v1/iam/confidential-governance-participants` | Sanctum | `iam.manage_capabilities` | Create governance config. |
| PUT | `/api/v1/iam/confidential-governance-participants/{participant}` | Sanctum | `iam.manage_capabilities` | Update scope/category applicability. |
| POST | `/api/v1/iam/confidential-governance-participants/{participant}/revoke` | Sanctum | `iam.manage_capabilities` | Revoke config (`revoked_at`). |

**Pagination response shape (cursor):**

```json
{
  "data": [...],
  "next_cursor": "eyJpZCI6MTAwfQ==",
  "has_more": true
}
```

---

## What to Test Manually

1. **Happy path — confidential task lifecycle:** Create confidential task → add named participant → participant sees task in board/search → remove participant → participant no longer sees it.
2. **Governance access:** Configure Minister's Office position as tenant-wide governance participant → launch confidential task → occupant sees it without being named.
3. **Governance scope mismatch:** Config with `scope_type=specific_department` but no department → 422.
4. **Metadata view:** Senior leader with `task.confidential.view_metadata` opens `/metadata` → sees redacted title, department, status, due date; full-visibility user gets 404.
5. **Content override:** Governance officer with `task.confidential.view_override` supplies reason → sees full task; audit event recorded with reason.
6. **Classification enforcement on search:** Search endpoint (`/v1/search`) returns only authorized confidential tasks.
7. **Classification enforcement on analytics:** Executive dashboard excludes confidential tasks unless viewer is authorized.
8. **Classification enforcement on comments/documents:** User who cannot see confidential task cannot see its comments or documents.
9. **Rate limiting:** 31st participant mutation in 1 minute → 429 with `Retry-After`.
10. **Tenant isolation:** Governance config created in tenant A does not affect tenant B.
11. **Audit trail:** `GET /v1/tasks/{task}/audit-trail` includes `confidential.participant_added`, `confidential.content_overridden`, etc.
12. **Initiator-managed participants:** Disable tenant setting → initiator can no longer add/remove without `task.confidential.manage_participants`.
13. **Redacted title toggle:** Enable `settings.confidentiality.metadata_show_actual_title` → metadata view shows actual title.
14. **Concurrent writes:** Two requests add same participant simultaneously → one succeeds, one returns 422 duplicate.
15. **Cache invalidation:** List governance configs → create new config → list again within 5 minutes shows new config.
