# Implementation Plan: 014 External References

> **Spec:** `specs/014-external-references/spec.md`
> **Date:** 2026-07-01
> **Status:** `completed`

---

## Open Questions Resolved

| # | Open Question | Decision | Rationale |
|---|---------------|----------|-----------|
| 1 | Capability name for external entity management | **`task.manage_external_entities`** | Clear, specific; added to `CapabilitySeeder`. |
| 2 | Who can attach references to a task | **Task initiator OR `task.manage` capability** | Mirrors Spec 005 draft-task edit rules; keeps mutation narrow while allowing admins to correct errors. |
| 3 | Can references be added to completed/cancelled tasks | **Yes** | References are metadata; allowed on any non-deleted task. |
| 4 | Deactivated entities rejected for new references | **Yes** | New references must point to active entities; historical references to deactivated entities remain visible. |
| 5 | Reference numbers unique per tenant | **No** | Same external document can legitimately link to multiple tasks. |
| 6 | Include reference numbers in full-text `q` search | **No** | Exact-match only via dedicated `external_reference` filter; avoids false positives in FTS. |
| 7 | Module location for `external_entities` | **Inside Task module** | Per `_blueprints/03_Module_Boundary_Map.md` Feature-to-Module mapping; avoids extra bounded context. |
| 8 | `task_external_references` soft deletes | **Add `deleted_at`** | Spec's DELETE endpoint requires soft-delete even though column list omitted `deleted_at`. Model uses `SoftDeletes`. |
| 9 | `TaskNotVisibleException` reuse | **Create new `TaskNotVisibleException`** | No such exception exists in the Task module; extend `DomainException` with 403 status. |

---

## Technical Approach

Add two tenant tables (`external_entities`, `task_external_references`) and a small API surface inside the existing Task module. External entities are a tenant-managed cached catalog; task references are child resources guarded by `TaskVisibilityScope` and the same initiator/`task.manage` rule used for task drafts. Search integration removes the `ExternalReferenceSearchNotAvailableException` guard and enriches results with matched reference metadata.

**Key decisions:**
- **Single module** — both tables live in `app/Modules/Task/` to avoid a one-table bounded context.
- **Reuse existing authorization patterns** — `TaskVisibilityScope` for read access, initiator/`task.manage` for mutation (same as `TaskController::update/destroy`).
- **Cached entity catalog** — warm-cache active entity list; invalidated by entity lifecycle events.
- **No FTS for references** — exact-match filter on `reference_number`; no denormalized search-index column needed.
- **No queued jobs** — domain events use `ShouldDispatchAfterCommit`; Audit listeners consume them.

---

## Affected Modules / Files

### New Files

```
app/Modules/Task/
├── Enums/
│   ├── ExternalEntityType.php
│   └── ExternalReferenceType.php
├── Models/
│   ├── ExternalEntity.php
│   └── TaskExternalReference.php
├── Services/
│   ├── ExternalEntityService.php
│   └── TaskExternalReferenceService.php
├── Controllers/
│   ├── ExternalEntityController.php
│   └── TaskExternalReferenceController.php
├── Requests/
│   ├── StoreExternalEntityRequest.php
│   ├── UpdateExternalEntityRequest.php
│   ├── StoreTaskExternalReferenceRequest.php
│   └── UpdateTaskExternalReferenceRequest.php
├── Resources/
│   ├── ExternalEntityResource.php
│   └── TaskExternalReferenceResource.php
├── Events/
│   ├── ExternalEntityCreated.php
│   ├── ExternalEntityUpdated.php
│   ├── ExternalEntityDeactivated.php
│   ├── ExternalEntityReactivated.php
│   ├── ExternalReferenceCreated.php
│   ├── ExternalReferenceUpdated.php
│   └── ExternalReferenceDeleted.php
└── Exceptions/
    ├── ExternalEntityNotFoundException.php
    ├── ExternalEntityInactiveException.php
    ├── ExternalReferenceNotFoundException.php
    └── TaskNotVisibleException.php

database/migrations/tenant/
├── 2026_07_01_000001_create_external_entities_table.php
└── 2026_07_01_000002_create_task_external_references_table.php

database/factories/
├── ExternalEntityFactory.php
└── TaskExternalReferenceFactory.php

tests/Feature/Modules/Task/
└── ExternalReferenceTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `database/seeders/CapabilitySeeder.php` | Add `task.manage_external_entities` capability. |
| `app/Modules/Audit/Enums/AuditEntityType.php` | Add `ExternalEntity = 32`, `ExternalReference = 33`. |
| `routes/api/v1/tasks.php` | Register external-entity and task external-reference routes. |
| `app/Modules/Search/Services/SearchService.php` | Remove `ExternalReferenceSearchNotAvailableException` guard; eager-load matched references when `external_reference` filter is used. |
| `app/Modules/Search/Resources/SearchTaskResource.php` | Include matched `external_references` array when loaded. |
| `lang/en/task.php` | Add exception messages for external reference domain errors. |
| `lang/ar/task.php` | Add Arabic exception messages. |
| `openapi/openapi.json` | Regenerate after implementation. |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create two int-backed enums in `app/Modules/Task/Enums/`; use `Rule::enum()` in Form Requests and enum cases in service logic.

**Key decisions:**
- Stored as TINYINT in migrations; cast in model `casts()`.
- TitleCase keys per `coding-standards.md`.

**Files:**
- `app/Modules/Task/Enums/ExternalEntityType.php`
- `app/Modules/Task/Enums/ExternalReferenceType.php`

**Code snippet — `ExternalReferenceType`:**
```php
<?php

namespace App\Modules\Task\Enums;

enum ExternalReferenceType: int
{
    case Correspondence = 1;
    case Contract = 2;
    case MinisterialDecision = 3;
    case AuthorityDecision = 4;
    case MeetingMinute = 5;
    case ExternalOrgRequest = 6;
    case VendorReference = 7;
    case Other = 8;
}
```

**Code snippet — `ExternalEntityType`:**
```php
<?php

namespace App\Modules\Task\Enums;

enum ExternalEntityType: int
{
    case GovernmentMinistry = 1;
    case GovernmentAuthority = 2;
    case SemiGovernment = 3;
    case University = 4;
    case Hospital = 5;
    case PrivateCompany = 6;
    case Vendor = 7;
    case Other = 8;
}
```

**Test cases:**
1. `ExternalReferenceType::Contract->value` → `2`
2. `ExternalEntityType::tryFrom(5)` → `ExternalEntityType::Hospital`

**Rules:** `coding-standards.md` § Enum Usage — PHP enum classes, `Rule::enum()` in requests, no magic numbers.

---

### 2. Migrations

**One-line summary:** Two additive tenant migrations with FKs, proper indexes, and no `tenant_id` columns.

**Key decisions:**
- `external_entities`: soft deletes, `is_active` default true.
- `task_external_references`: includes `deleted_at` for soft delete, composite index on `(task_id)` and `(reference_number)`.
- UUID v7 `public_id` via `TenantModel` boot.

**Files:**
- `database/migrations/tenant/2026_07_01_000001_create_external_entities_table.php`
- `database/migrations/tenant/2026_07_01_000002_create_task_external_references_table.php`

**Code snippet — `create_external_entities_table`:**
```php
Schema::create('external_entities', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->string('name_ar');
    $table->string('name_en')->nullable();
    $table->unsignedTinyInteger('entity_type');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();

    $table->index('entity_type');
    $table->index('is_active');
});
```

**Code snippet — `create_task_external_references_table`:**
```php
Schema::create('task_external_references', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->unsignedTinyInteger('reference_type');
    $table->string('reference_number', 100);
    $table->foreignId('external_entity_id')->nullable()->constrained('external_entities')->nullOnDelete();
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index('task_id');
    $table->index('reference_number');
    $table->index(['task_id', 'reference_number']);
});
```

**Rules:** `coding-standards.md` § Migrations — no `tenant_id`, use `constrained()`, tenant migrations only.

---

### 3. Models

**One-line summary:** Extend `TenantModel`, use `#[Fillable]`, define casts and relationships.

**Key decisions:**
- `ExternalEntity` uses `SoftDeletes`; `TaskExternalReference` uses `SoftDeletes` for the soft-delete endpoint.
- Route model binding by `public_id` inherited from `TenantModel`.

**Files:**
- `app/Modules/Task/Models/ExternalEntity.php`
- `app/Modules/Task/Models/TaskExternalReference.php`

**Code snippet — `ExternalEntity`:**
```php
<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_ar', 'name_en', 'entity_type', 'is_active'])]
class ExternalEntity extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'entity_type' => ExternalEntityType::class,
            'is_active' => 'boolean',
        ];
    }

    public function taskExternalReferences(): HasMany
    {
        return $this->hasMany(TaskExternalReference::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

**Code snippet — `TaskExternalReference`:**
```php
<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'reference_type', 'reference_number', 'external_entity_id', 'notes'])]
class TaskExternalReference extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'reference_type' => ExternalReferenceType::class,
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function externalEntity(): BelongsTo
    {
        return $this->belongsTo(ExternalEntity::class);
    }
}
```

**Test cases:**
1. `ExternalEntity::factory()->create(['name_en' => null]); $entity->name_en` → equals `name_ar` (service fallback).
2. `TaskExternalReference::factory()->create(['external_entity_id' => null])->externalEntity` → `null`.

**Rules:** `coding-standards.md` § Models — no `tenant_id`, `casts()` method, eager-load relationships in resources.

---

### 4. Exceptions

**One-line summary:** Domain exceptions extend `App\Exceptions\DomainException`; rendered automatically by existing handler.

**Key decisions:**
- `ExternalEntityNotFoundException` and `ExternalReferenceNotFoundException` → 404.
- `ExternalEntityInactiveException` → 422.
- `TaskNotVisibleException` → 403.

**Files:**
- `app/Modules/Task/Exceptions/ExternalEntityNotFoundException.php`
- `app/Modules/Task/Exceptions/ExternalEntityInactiveException.php`
- `app/Modules/Task/Exceptions/ExternalReferenceNotFoundException.php`
- `app/Modules/Task/Exceptions/TaskNotVisibleException.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class TaskNotVisibleException extends DomainException
{
    protected int $statusCode = 403;

    public function __construct()
    {
        parent::__construct(__('task.exceptions.task_not_visible'));
    }
}
```

**Rules:** `coding-standards.md` § Error Handling — extend `DomainException`, register via base handler in `bootstrap/app.php` (already done).

---

### 5. Services

#### 5a. `ExternalEntityService`

**One-line summary:** CRUD for the external entity catalog with warm-cache active list and event emission.

**Key decisions:**
- `getActive()` cached at `{tenant_slug}:task:external_entities:active` TTL 300s.
- Mutations clear cache and emit events.
- Single writes; no `DB::transaction()` needed.
- All methods use try/catch + `Log::channel('task')`.

**File:** `app/Modules/Task/Services/ExternalEntityService.php`

**Code snippet — cache + create:**
```php
<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Enums\ExternalEntityType;
use App\Modules\Task\Events\ExternalEntityCreated;
use App\Modules\Task\Events\ExternalEntityDeactivated;
use App\Modules\Task\Events\ExternalEntityReactivated;
use App\Modules\Task\Events\ExternalEntityUpdated;
use App\Modules\Task\Models\ExternalEntity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExternalEntityService
{
    public function getActive(): Collection
    {
        $tenantSlug = tenant()?->slug ?? 'central';

        return Cache::remember("{$tenantSlug}:task:external_entities:active", 300, function () {
            return ExternalEntity::active()->orderBy('name_ar')->get();
        });
    }

    public function create(array $data): ExternalEntity
    {
        try {
            $entity = ExternalEntity::create([
                'name_ar' => $data['name_ar'],
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : $data['name_ar'],
                'entity_type' => $data['entity_type'],
                'is_active' => true,
            ]);

            $this->clearCache();
            event(new ExternalEntityCreated($entity));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.create',
                'entity_type' => 'external_entity',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(ExternalEntity $entity, array $data): ExternalEntity
    {
        try {
            $entity->update([
                'name_ar' => $data['name_ar'] ?? $entity->name_ar,
                'name_en' => ! empty($data['name_en']) ? $data['name_en'] : ($data['name_ar'] ?? $entity->name_en),
                'entity_type' => $data['entity_type'] ?? $entity->entity_type,
            ]);

            $this->clearCache();
            event(new ExternalEntityUpdated($entity->fresh()));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.update',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(ExternalEntity $entity): ExternalEntity
    {
        try {
            $entity->update(['is_active' => false]);
            $this->clearCache();
            event(new ExternalEntityDeactivated($entity));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to deactivate external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.deactivate',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(ExternalEntity $entity): ExternalEntity
    {
        try {
            $entity->update(['is_active' => true]);
            $this->clearCache();
            event(new ExternalEntityReactivated($entity));

            return $entity->fresh();
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to reactivate external entity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_entity.reactivate',
                'entity_type' => 'external_entity',
                'entity_id' => $entity->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function clearCache(): void
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        Cache::forget("{$tenantSlug}:task:external_entities:active");
    }
}
```

**Test cases:**
1. After `create()`, `Cache::get('{slug}:task:external_entities:active')` is cleared and next `getActive()` returns the new entity.
2. `deactivate()` sets `is_active = false` and clears cache.

**Rules:** `coding-standards.md` § Caching (warm 300s, tenant-prefixed, event-invalidated), § Error Handling (try/catch + `task` channel).

---

#### 5b. `TaskExternalReferenceService`

**One-line summary:** Attach/update/remove references on a task with active-entity validation and event emission.

**Key decisions:**
- Visibility check done in controller via `TaskVisibilityScope`; service receives already-visible task.
- New references require active entity; updates changing entity require active entity.
- Soft delete on remove.
- Cursor pagination ordered by `id` ascending.

**File:** `app/Modules/Task/Services/TaskExternalReferenceService.php`

**Code snippet — create/update:**
```php
<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Events\ExternalReferenceCreated;
use App\Modules\Task\Events\ExternalReferenceDeleted;
use App\Modules\Task\Events\ExternalReferenceUpdated;
use App\Modules\Task\Exceptions\ExternalEntityInactiveException;
use App\Modules\Task\Exceptions\ExternalEntityNotFoundException;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

class TaskExternalReferenceService
{
    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        try {
            return TaskExternalReference::where('task_id', $task->id)
                ->with('externalEntity')
                ->orderBy('id')
                ->cursorPaginate($perPage);
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to list task external references', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.list',
                'entity_type' => 'task',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function create(Task $task, array $data, User $user): TaskExternalReference
    {
        try {
            $entityId = $this->resolveActiveEntityId($data['external_entity_id'] ?? null);

            $reference = TaskExternalReference::create([
                'task_id' => $task->id,
                'reference_type' => $data['reference_type'],
                'reference_number' => $data['reference_number'],
                'external_entity_id' => $entityId,
                'notes' => $data['notes'] ?? null,
            ]);

            event(new ExternalReferenceCreated($reference->load('externalEntity'), $user));

            return $reference->fresh(['externalEntity']);
        } catch (ExternalEntityNotFoundException|ExternalEntityInactiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.create',
                'entity_type' => 'external_reference',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(TaskExternalReference $reference, array $data, User $user): TaskExternalReference
    {
        try {
            $newEntityId = $data['external_entity_id'] ?? null;
            if ($newEntityId !== null && $newEntityId !== $reference->external_entity_id?->public_id) {
                $reference->external_entity_id = $this->resolveActiveEntityId($newEntityId);
            }

            $reference->update([
                'reference_type' => $data['reference_type'] ?? $reference->reference_type,
                'reference_number' => $data['reference_number'] ?? $reference->reference_number,
                'notes' => $data['notes'] ?? $reference->notes,
            ]);

            event(new ExternalReferenceUpdated($reference->fresh(['externalEntity']), $user));

            return $reference->fresh(['externalEntity']);
        } catch (ExternalEntityNotFoundException|ExternalEntityInactiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to update external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.update',
                'entity_type' => 'external_reference',
                'entity_id' => $reference->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(TaskExternalReference $reference, User $user): void
    {
        try {
            $reference->delete();
            event(new ExternalReferenceDeleted($reference, $user));
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to delete external reference', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'external_reference.delete',
                'entity_type' => 'external_reference',
                'entity_id' => $reference->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function resolveActiveEntityId(?string $publicId): ?int
    {
        if ($publicId === null) {
            return null;
        }

        $entity = ExternalEntity::where('public_id', $publicId)->first();

        if (! $entity) {
            throw new ExternalEntityNotFoundException;
        }

        if (! $entity->is_active) {
            throw new ExternalEntityInactiveException;
        }

        return $entity->id;
    }
}
```

**Test cases:**
1. Create reference with inactive entity → throws `ExternalEntityInactiveException` (422).
2. Create reference without entity → succeeds, `external_entity_id` is null.

**Rules:** `coding-standards.md` § Database Transactions (single writes, no transaction), § Error Handling (try/catch + `task` channel), § Events (`ShouldDispatchAfterCommit`).

---

### 6. Controllers

**One-line summary:** Thin controllers validate requests, check rate limits, enforce visibility/mutation rules, and return API Resources.

**Key decisions:**
- `ExternalEntityController`: `capability:task.manage_external_entities` middleware on mutate routes.
- `TaskExternalReferenceController`: `TaskVisibilityScope` check on all routes; initiator/`task.manage` check on mutations.
- Rate limits via `HasRateLimiting` trait.

**Files:**
- `app/Modules/Task/Controllers/ExternalEntityController.php`
- `app/Modules/Task/Controllers/TaskExternalReferenceController.php`

**Code snippet — `TaskExternalReferenceController`:**
```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use App\Modules\Task\Requests\StoreTaskExternalReferenceRequest;
use App\Modules\Task\Requests\UpdateTaskExternalReferenceRequest;
use App\Modules\Task\Resources\TaskExternalReferenceResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\TaskExternalReferenceService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskExternalReferenceController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private TaskExternalReferenceService $referenceService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->referenceService->listForTask($task, $request->integer('per_page', 15))
            ->through(fn (TaskExternalReference $r) => new TaskExternalReferenceResource($r));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreTaskExternalReferenceRequest $request, Task $task): TaskExternalReferenceResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $reference = $this->referenceService->create($task, $request->validated(), $user);

        return new TaskExternalReferenceResource($reference);
    }

    public function update(UpdateTaskExternalReferenceRequest $request, Task $task, TaskExternalReference $reference): TaskExternalReferenceResource
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $reference = $this->referenceService->update($reference, $request->validated(), $user);

        return new TaskExternalReferenceResource($reference);
    }

    public function destroy(Request $request, Task $task, TaskExternalReference $reference): JsonResponse
    {
        $user = $request->user();
        $this->guardVisible($task, $user);
        $this->guardCanMutate($task, $user);
        $this->checkRateLimit(RateLimits::MUTATE, [$user->public_id]);

        $this->referenceService->delete($reference, $user);

        return response()->json(null, 204);
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            abort(403, 'You do not have access to this task.');
        }
    }

    private function guardCanMutate(Task $task, User $user): void
    {
        if ($task->initiator_user_id === $user->id) {
            return;
        }

        if ($this->iamPolicy->hasCapability($user, 'task.manage')) {
            return;
        }

        abort(403, 'Only the task initiator or a user with task.manage can modify external references.');
    }
}
```

**Code snippet — `ExternalEntityController`:**
```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Requests\StoreExternalEntityRequest;
use App\Modules\Task\Requests\UpdateExternalEntityRequest;
use App\Modules\Task\Resources\ExternalEntityResource;
use App\Modules\Task\Services\ExternalEntityService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalEntityController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ExternalEntityService $entityService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return ExternalEntityResource::collection($this->entityService->getActive());
    }

    public function store(StoreExternalEntityRequest $request): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $entity = $this->entityService->create($request->validated());

        return new ExternalEntityResource($entity);
    }

    public function show(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new ExternalEntityResource($entity);
    }

    public function update(UpdateExternalEntityRequest $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $entity = $this->entityService->update($entity, $request->validated());

        return new ExternalEntityResource($entity);
    }

    public function deactivate(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        return new ExternalEntityResource($this->entityService->deactivate($entity));
    }

    public function reactivate(Request $request, ExternalEntity $entity): ExternalEntityResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        return new ExternalEntityResource($this->entityService->reactivate($entity));
    }
}
```

**Rules:** `coding-standards.md` § Controllers (thin, no business logic), § Rate Limiting (`HasRateLimiting` trait, `RateLimits` constants).

---

### 7. Form Requests

**One-line summary:** Validation classes using `Rule::enum()`; `authorize()` returns `true`.

**Files:**
- `app/Modules/Task/Requests/StoreExternalEntityRequest.php`
- `app/Modules/Task/Requests/UpdateExternalEntityRequest.php`
- `app/Modules/Task/Requests/StoreTaskExternalReferenceRequest.php`
- `app/Modules/Task/Requests/UpdateTaskExternalReferenceRequest.php`

**Code snippet — `StoreExternalEntityRequest`:**
```php
<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ExternalEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExternalEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['required', Rule::enum(ExternalEntityType::class)],
        ];
    }
}
```

**Code snippet — `StoreTaskExternalReferenceRequest`:**
```php
<?php

namespace App\Modules\Task\Requests;

use App\Modules\Task\Enums\ExternalReferenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskExternalReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_type' => ['required', Rule::enum(ExternalReferenceType::class)],
            'reference_number' => ['required', 'string', 'max:100'],
            'external_entity_id' => ['nullable', 'string', 'uuid', 'exists:external_entities,public_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

**Rules:** `coding-standards.md` § Enum Usage, § Validation (Form Request classes).

---

### 8. API Resources

**One-line summary:** Transform models to JSON, expose only `public_id`, include nested entity metadata.

**Files:**
- `app/Modules/Task/Resources/ExternalEntityResource.php`
- `app/Modules/Task/Resources/TaskExternalReferenceResource.php`

**Code snippet — `TaskExternalReferenceResource`:**
```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskExternalReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'reference_type' => $this->reference_type,
            'reference_number' => $this->reference_number,
            'external_entity' => $this->whenLoaded('externalEntity', fn () => new ExternalEntityResource($this->externalEntity)),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Code snippet — `ExternalEntityResource`:**
```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExternalEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'entity_type' => $this->entity_type,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` § API Resources — `public_id` only, eager-load relationships to avoid N+1.

---

### 9. Events & Audit

**One-line summary:** Seven domain events implementing `ShouldDispatchAfterCommit` and `ProvidesAuditData`; Audit module records them automatically.

**Key decisions:**
- Add `ExternalEntity = 32` and `ExternalReference = 33` to `AuditEntityType`.
- Events carry the acting `User` for audit attribution.
- Task-level reference events root to the parent `Task`.

**Files:**
- `app/Modules/Task/Events/ExternalEntityCreated.php`
- `app/Modules/Task/Events/ExternalEntityUpdated.php`
- `app/Modules/Task/Events/ExternalEntityDeactivated.php`
- `app/Modules/Task/Events/ExternalEntityReactivated.php`
- `app/Modules/Task/Events/ExternalReferenceCreated.php`
- `app/Modules/Task/Events/ExternalReferenceUpdated.php`
- `app/Modules/Task/Events/ExternalReferenceDeleted.php`
- `app/Modules/Audit/Enums/AuditEntityType.php` (add cases)

**Code snippet — `ExternalReferenceCreated`:**
```php
<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ExternalReferenceCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskExternalReference $reference,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'external_reference.created',
            entityType: AuditEntityType::ExternalReference,
            entityId: $this->reference->id,
            entityPublicId: $this->reference->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->reference->task_id,
            rootEntityPublicId: $this->reference->task?->public_id,
            user: $this->user,
            payload: [
                'reference_type' => $this->reference->reference_type->name,
                'reference_number' => $this->reference->reference_number,
            ],
        );
    }
}
```

**Code snippet — `AuditEntityType` additions:**
```php
case ExternalEntity = 32;
case ExternalReference = 33;
```

Add to `name()`:
```php
self::ExternalEntity => 'external_entity',
self::ExternalReference => 'external_reference',
```

**Test cases:**
1. Creating a reference dispatches `ExternalReferenceCreated`; Audit listener persists a row with `entity_type = external_reference`.
2. Deactivating an entity dispatches `ExternalEntityDeactivated` with `is_active` payload.

**Rules:** `coding-standards.md` § Database Transactions (`ShouldDispatchAfterCommit` is non-negotiable), § Error Handling — audit is append-only, Task module never writes `audit_events` directly.

---

### 10. Routes

**One-line summary:** Append external-entity and task external-reference routes to existing `routes/api/v1/tasks.php`.

**File:** `routes/api/v1/tasks.php`

**Code snippet:**
```php
use App\Modules\Task\Controllers\ExternalEntityController;
use App\Modules\Task\Controllers\TaskExternalReferenceController;

Route::middleware(['auth:sanctum'])->prefix('tasks')->group(function () {
    // ... existing routes ...

    // External Entities
    Route::get('external-entities', [ExternalEntityController::class, 'index']);
    Route::get('external-entities/{entity}', [ExternalEntityController::class, 'show']);
    Route::middleware(['capability:task.manage_external_entities'])->group(function () {
        Route::post('external-entities', [ExternalEntityController::class, 'store']);
        Route::put('external-entities/{entity}', [ExternalEntityController::class, 'update']);
        Route::post('external-entities/{entity}/deactivate', [ExternalEntityController::class, 'deactivate']);
        Route::post('external-entities/{entity}/reactivate', [ExternalEntityController::class, 'reactivate']);
    });

    // Task External References
    Route::get('{task}/external-references', [TaskExternalReferenceController::class, 'index']);
    Route::post('{task}/external-references', [TaskExternalReferenceController::class, 'store']);
    Route::put('{task}/external-references/{reference}', [TaskExternalReferenceController::class, 'update']);
    Route::delete('{task}/external-references/{reference}', [TaskExternalReferenceController::class, 'destroy']);
});
```

**Rules:** `coding-standards.md` § Rate Limiting (applied in controllers, not routes), kebab-case paths.

---

### 11. Search Integration

**One-line summary:** Remove the table-existence guard in `SearchService` and enrich results with matched reference metadata when `external_reference` is used.

**Key decisions:**
- Delete `ExternalReferenceSearchNotAvailableException` throw.
- Eager-load matching references onto paginated tasks when filter is present.
- `SearchTaskResource` includes `external_references` array if loaded.

**Files:**
- `app/Modules/Task/Models/Task.php` (add `externalReferences` relationship)
- `app/Modules/Search/Services/SearchService.php`
- `app/Modules/Search/Resources/SearchTaskResource.php`

**Code snippet — `Task` relationship:**
```php
public function externalReferences(): HasMany
{
    return $this->hasMany(TaskExternalReference::class)->orderBy('id');
}
```

**Code snippet — `SearchService::searchTasks()` modification (after structured filters):**
```php
if (! empty($filters['external_reference'])) {
    $referenceNumber = $filters['external_reference'];
    $query->whereExists(function ($sub) use ($referenceNumber) {
        $sub->selectRaw('1')
            ->from('task_external_references')
            ->whereColumn('task_external_references.task_id', 'tasks.id')
            ->where('task_external_references.reference_number', $referenceNumber)
            ->whereNull('task_external_references.deleted_at');
    });

    $query->with([
        'externalReferences' => fn ($q) => $q->where('reference_number', $referenceNumber)->with('externalEntity'),
    ]);
}
```

**Code snippet — `SearchTaskResource` addition:**
```php
'external_references' => $this->when(
    $task->relationLoaded('externalReferences') && $task->externalReferences->isNotEmpty(),
    fn () => TaskExternalReferenceResource::collection($task->externalReferences)
),
```

**Test cases:**
1. `GET /v1/search/tasks?external_reference=وارد-2026-00412` returns only tasks with that exact number and includes the reference in each result.
2. `GET /v1/search/tasks?q=وارد-2026-00412` does **not** match by reference number (only title/description/notes/display_id).

**Rules:** `coding-standards.md` § Pagination — search uses cursor pagination; no caching of search results.

---

### 12. Capability Seeder

**One-line summary:** Add `task.manage_external_entities` to the system capability catalog.

**File:** `database/seeders/CapabilitySeeder.php`

**Code snippet:**
```php
['key' => 'task.manage_external_entities', 'name_ar' => 'إدارة الجهات الخارجية', 'name_en' => 'Manage External Entities', 'description' => 'Can create, update, deactivate, and reactivate the external entity catalog.'],
```

**Rules:** `coding-standards.md` § Enum Usage / capabilities as named strings; no hardcoded roles.

---

### 13. Translations

**One-line summary:** Add bilingual exception strings to `lang/{en,ar}/task.php`.

**Files:**
- `lang/en/task.php`
- `lang/ar/task.php`

**Code snippet — `lang/en/task.php` additions:**
```php
'external_entity_not_found' => 'External entity not found.',
'external_entity_inactive' => 'External entity is inactive and cannot be used for new references.',
'external_reference_not_found' => 'External reference not found.',
'task_not_visible' => 'You do not have access to this task.',
```

**Code snippet — `lang/ar/task.php` additions:**
```php
'external_entity_not_found' => 'الجهة الخارجية غير موجودة.',
'external_entity_inactive' => 'الجهة الخارجية غير نشطة ولا يمكن استخدامها للإشارات الجديدة.',
'external_reference_not_found' => 'الإشارة الخارجية غير موجودة.',
'task_not_visible' => 'ليس لديك حق الوصول إلى هذه المهمة.',
```

---

### 14. Factories

**One-line summary:** Factories for test data following existing project patterns.

**Files:**
- `database/factories/ExternalEntityFactory.php`
- `database/factories/TaskExternalReferenceFactory.php`

**Code snippet — `ExternalEntityFactory`:**
```php
<?php

namespace Database\Factories;

use App\Modules\Task\Enums\ExternalEntityType;
use App\Modules\Task\Models\ExternalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExternalEntityFactory extends Factory
{
    protected $model = ExternalEntity::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->company(),
            'name_en' => fake()->company(),
            'entity_type' => fake()->randomElement(ExternalEntityType::cases()),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
```

**Code snippet — `TaskExternalReferenceFactory`:**
```php
<?php

namespace Database\Factories;

use App\Modules\Task\Enums\ExternalReferenceType;
use App\Modules\Task\Models\ExternalEntity;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskExternalReference;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskExternalReferenceFactory extends Factory
{
    protected $model = TaskExternalReference::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'reference_type' => fake()->randomElement(ExternalReferenceType::cases()),
            'reference_number' => fake()->unique()->ean8(),
            'external_entity_id' => ExternalEntity::factory(),
            'notes' => fake()->sentence(),
        ];
    }
}
```

---

### 15. Feature Tests

**One-line summary:** Pest feature tests covering CRUD, authorization, search integration, and edge cases.

**File:** `tests/Feature/Modules/Task/ExternalReferenceTest.php`

**Key test cases:**
1. Create external entity with `name_en` fallback.
2. Update external entity.
3. Deactivate/reactivate entity.
4. List active entities (full list, no pagination envelope).
5. Create task reference with active entity.
6. Reject creating task reference with inactive entity → 422.
7. List task references (cursor pagination shape).
8. Update task reference.
9. Delete task reference (soft delete).
10. ABAC deny: user without task visibility cannot list references → 403.
11. Confidential task: references visible only to authorized users.
12. Search by exact reference number returns task and matched reference metadata.
13. Search with no match returns empty cursor page.
14. Invalid `reference_type` rejected → 422.
15. Non-initiator without `task.manage` cannot create reference → 403.

**Rules:** `testing-policy.md` — feature tests mandatory; `RefreshDatabase`; use factories; assert cursor pagination.

---

## Execution Order

| Step | What | Depends On |
|------|------|------------|
| 1 | Create enums (`ExternalEntityType`, `ExternalReferenceType`). | — |
| 2 | Create migrations (`external_entities`, `task_external_references`). | Step 1 |
| 3 | Create models (`ExternalEntity`, `TaskExternalReference`) + `Task::externalReferences()` relationship. | Step 2 |
| 4 | Create exceptions. | — |
| 5 | Create domain events + update `AuditEntityType` enum. | Step 3 |
| 6 | Update `CapabilitySeeder` with `task.manage_external_entities`. | — |
| 7 | Create API Resources. | Step 3 |
| 8 | Create Form Requests. | Step 1 |
| 9 | Create `ExternalEntityService` + `TaskExternalReferenceService`. | Steps 3–5 |
| 10 | Create controllers (`ExternalEntityController`, `TaskExternalReferenceController`). | Steps 7–9 |
| 11 | Register routes in `routes/api/v1/tasks.php`. | Step 10 |
| 12 | Integrate Search: update `SearchService` and `SearchTaskResource`. | Step 3 |
| 13 | Add translations. | Step 4 |
| 14 | Create factories. | Step 3 |
| 15 | Write feature tests. | Steps 2–14 |
| 16 | Run migrations on template DB and run tests. | Step 15 |
| 17 | Run `vendor/bin/pint --dirty --format agent`. | Step 16 |
| 18 | Regenerate `openapi/openapi.json`. | Step 11 |

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Rate Limit | Description |
|--------|----------|------|------------|------------|-------------|
| GET | `/api/v1/tasks/external-entities` | Sanctum | — | `LIST` | Full list of active external entities (ordered by `name_ar`). |
| POST | `/api/v1/tasks/external-entities` | Sanctum | `task.manage_external_entities` | `MUTATE` | Create external entity. |
| GET | `/api/v1/tasks/external-entities/{entity}` | Sanctum | — | `LIST` | Show external entity. |
| PUT | `/api/v1/tasks/external-entities/{entity}` | Sanctum | `task.manage_external_entities` | `MUTATE` | Update external entity. |
| POST | `/api/v1/tasks/external-entities/{entity}/deactivate` | Sanctum | `task.manage_external_entities` | `MUTATE` | Deactivate entity. |
| POST | `/api/v1/tasks/external-entities/{entity}/reactivate` | Sanctum | `task.manage_external_entities` | `MUTATE` | Reactivate entity. |
| GET | `/api/v1/tasks/{task}/external-references` | Sanctum | task visibility | `LIST` | Cursor-paginated list of references on a visible task. |
| POST | `/api/v1/tasks/{task}/external-references` | Sanctum | visibility + initiator/`task.manage` | `MUTATE` | Attach reference to task. |
| PUT | `/api/v1/tasks/{task}/external-references/{reference}` | Sanctum | visibility + initiator/`task.manage` | `MUTATE` | Update a reference. |
| DELETE | `/api/v1/tasks/{task}/external-references/{reference}` | Sanctum | visibility + initiator/`task.manage` | `MUTATE` | Soft-delete a reference. |

### Request/Response Examples

**Create external entity:**
```http
POST /api/v1/tasks/external-entities
{
  "name_ar": "وزارة المالية",
  "name_en": "Ministry of Finance",
  "entity_type": 1
}
```

**Attach reference:**
```http
POST /api/v1/tasks/{task_public_id}/external-references
{
  "reference_type": 1,
  "reference_number": "وارد-2026-00412",
  "external_entity_id": "uuid-of-external-entity",
  "notes": "Received via official channel"
}
```

**List references response:**
```json
{
  "data": [
    {
      "public_id": "...",
      "reference_type": "correspondence",
      "reference_number": "وارد-2026-00412",
      "external_entity": {
        "public_id": "...",
        "name_ar": "وزارة المالية",
        "name_en": "Ministry of Finance",
        "entity_type": "governmentministry",
        "is_active": true
      },
      "notes": "Received via official channel",
      "created_at": "2026-07-01T12:00:00Z"
    }
  ],
  "next_cursor": null,
  "has_more": false
}
```

**Search by reference number response (excerpt):**
```json
{
  "data": [
    {
      "public_id": "...",
      "title_ar": "...",
      "status": "active",
      "external_references": [
        {
          "public_id": "...",
          "reference_type": "correspondence",
          "reference_number": "وارد-2026-00412",
          "external_entity": { "public_id": "...", "name_ar": "..." }
        }
      ]
    }
  ],
  "next_cursor": null,
  "has_more": false
}
```

---

## What to Test Manually

1. **Create external entity** — Verify `name_en` falls back to `name_ar` when omitted.
2. **Deactivate entity** — Verify entity disappears from active list but historical references still show it.
3. **Attach reference with inactive entity** — Expect 422 with `external_entity_inactive` message.
4. **Attach reference on completed task** — Expect success (references are metadata, not status-dependent).
5. **List references with cursor pagination** — Verify `{data, next_cursor, has_more}` shape.
6. **Delete reference** — Verify 204 and the reference no longer appears in list.
7. **ABAC visibility denial** — Log in as a user who cannot view the task; expect 403 on list/create.
8. **Confidential task** — Reference list should be invisible to non-participants.
9. **Search exact match** — `GET /v1/search/tasks?external_reference=وارد-2026-00412` returns matching tasks with reference metadata.
10. **Search no match** — Empty `data` array with `has_more: false`.
11. **Search `q` does not match reference numbers** — Create a task whose only matching text is a reference number; search by `q` should not return it.
12. **Rate limiting** — Fire 31 mutate requests in a minute; expect 429 on the 31st.
13. **Cache invalidation** — Create an entity, then immediately list active entities; verify it appears within cache TTL.
14. **Audit trail** — Create/update/delete a reference; verify `audit_events` rows are written with correct `entity_type` and root task.
