# Implementation Plan: 016 Delegation & Out-of-Office Supplement

> **Spec:** `specs/016-delegation-oof/spec.md`
> **Date:** 2026-07-01
> **Status:** `completed`

---

## Open Questions Resolved

| # | Question | Decision | Rationale |
|---|----------|----------|-----------|
| 1 | Precedence when scoped delegation and simple OOF delegate both exist | **Scoped delegation wins when it matches; otherwise fall back to simple OOF delegate; otherwise original assignee.** | Scoped rules are more specific expressions of intent. Documented in `IamPolicy::resolveDelegateForAssignment()`. |
| 2 | Overlapping scoped delegations for same delegator | **Allowed. Most recently created active delegation wins at resolution time.** | Matches existing `003` "most recent wins" behavior; avoids arbitrary rejection rules. |
| 3 | Auto-expiry command frequency | **Every minute via scheduler, idempotent deactivation.** | Delegations are time-bound; one-minute granularity is sufficient for MVP without adding per-second polling. |
| 4 | Expired delegation physical deletion | **Only mark `is_active = false`.** | Preserves audit history and allows future V2 delegation-history features. |
| 5 | New `iam.view_delegations` capability | **Yes, add it to the catalog and seed it for tenant admins.** | Keeps read access separate from `iam.manage_users`; follows principle of least privilege. |
| 6 | Sub-stage `stage_type_id` context | **Sub-stages inherit `stage_type_id` from their parent `BlueprintStage`.** | `blueprint_sub_stages` table has no `stage_type_id` column (confirmed in `004-blueprint-engine`). `AssignmentResolutionService` resolves it via `$blueprintSubStage->stage->stage_type_id`. |
| 7 | Cross-module validation of blueprint_category / stage_type | **Allowed in form requests as read-only `exists` validation rules.** | No ORM joins; simple existence checks against stable reference tables. Does not violate module-boundary rules because validation is not business logic. |

---

## Technical Approach

Make the existing `delegations` table functional for scoped routing, add automatic expiry, and expose an active-delegation dashboard — all within the IAM module boundary, with a single consumer-side change in `AssignmentResolutionService`.

### Key Decisions

- **`IamPolicy::resolveDelegateForAssignment()`** becomes the single source of truth for assignment-time delegation resolution. It evaluates scoped delegations first, then simple OOF, then returns `null`.
- **`AssignmentResolutionService` derives context internally** (`task->blueprint->category_id` and stage/sub-stage `stage_type_id`) so callers like `TaskService` and `StageLifecycleService` do not change signatures.
- **Validation lives in form requests** with conditional `required_if`/`prohibited_unless` rules and `exists` lookups by `public_id`.
- **Auto-expiry uses the same command+job pattern as Spec 007** (`CheckSlaTimersCommand` → `CheckSlaTimersJob`): a platform command dispatches per-tenant queued jobs; the job initializes tenancy and runs the expiry service.
- **No Redis caching** on delegation lists or resolution; state is time-sensitive. Per-request `IamPolicy` memory cache remains.
- **`DelegationExpired` event** implements `ProvidesAuditData` so Audit module records it automatically via the generic `RecordAuditEvent` listener.

---

## Affected Modules / Files

### New Files

```
app/
├── Modules/Iam/
│   ├── Commands/
│   │   └── ExpireDelegationsCommand.php       # Dispatches per-tenant expiry jobs
│   ├── Jobs/
│   │   └── ExpireDelegationsJob.php           # Tenant-aware worker
│   ├── Services/
│   │   └── DelegationExpiryService.php        # Scan + deactivate expired delegations
│   ├── Events/
│   │   └── DelegationExpired.php              # ShouldDispatchAfterCommit + ProvidesAuditData
│   ├── Exceptions/
│   │   └── DelegationScopeMismatchException.php  # 422 for invalid scope/ID combos
│   └── Requests/
│       └── ListActiveDelegationsRequest.php   # Filters + per_page validation
tests/
└── Feature/Modules/Iam/
    ├── DelegationExpiryTest.php               # Command + job expiry behavior
    ├── DelegationScopedRoutingTest.php        # IamPolicy + assignment resolution
    └── ActiveDelegationTest.php               # GET /delegations/active endpoint
```

### Modified Files

| File | Change |
|------|--------|
| `app/Modules/Iam/Services/IamPolicy.php` | Add `resolveDelegateForAssignment()`; keep `resolveAssignee()` for simple OOF backward compatibility. |
| `app/Modules/Iam/Services/DelegationService.php` | Add scope-field normalization; validate scope/ID consistency in service as safety net. |
| `app/Modules/Iam/Requests/StoreDelegationRequest.php` | Conditional validation for `blueprint_category_id` and `stage_type_id` by `public_id`. |
| `app/Modules/Iam/Requests/UpdateDelegationRequest.php` | Same conditional validation as store. |
| `app/Modules/Iam/Controllers/DelegationController.php` | Add `active()` method; update `index()` to support `active_now` filter. |
| `app/Modules/Iam/Resources/DelegationResource.php` | Optionally include `blueprint_category` / `stage_type` nested objects when loaded. |
| `app/Modules/Iam/Models/Delegation.php` | Add `blueprintCategory()` and `stageType()` relationships (read-only, no FK enforcement at DB level). |
| `app/Modules/Task/Services/AssignmentResolutionService.php` | Replace `resolveAssignee()` calls with `resolveDelegateForAssignment()` using task/stage context. |
| `database/seeders/CapabilitySeeder.php` | Add `iam.view_delegations` capability. |
| `routes/api/v1/iam.php` | Add `GET /delegations/active`; update delegation group middleware to allow `iam.view_delegations`. |
| `routes/console.php` | Register `iam:expire-delegations` every minute. |
| `lang/en/iam.php` / `lang/ar/iam.php` | Add exception messages for scope mismatch. |
| `openapi/openapi.json` | Regenerate after routes/resources change. |
| `specs/016-delegation-oof/spec.md` | Update open questions (this plan resolves them). |

---

## Implementation Notes

### 1. `IamPolicy::resolveDelegateForAssignment()`

**One-line summary:** Given a user and task context, return the active delegate if a scoped delegation or simple OOF delegate applies.

**Key decisions:**
- Query active delegations ordered by `created_at DESC` so the most recent wins.
- Match against `DelegationScopeType` cases using enum comparison, never raw integers.
- Fall back to simple OOF delegate only if no scoped delegation matches.
- Return `null` when no delegation applies so the caller keeps the original assignee.

**Files to edit:** `app/Modules/Iam/Services/IamPolicy.php`

**Code snippet:**

```php
use App\Enums\DelegationScopeType;

public function resolveDelegateForAssignment(
    User $user,
    ?int $blueprintCategoryId,
    ?int $stageTypeId,
): ?User {
    $scopedDelegate = $this->resolveScopedDelegate($user, $blueprintCategoryId, $stageTypeId);

    if ($scopedDelegate !== null) {
        return $scopedDelegate;
    }

    return $this->resolveSimpleOutOfOfficeDelegate($user);
}

private function resolveScopedDelegate(
    User $user,
    ?int $blueprintCategoryId,
    ?int $stageTypeId,
): ?User {
    return Delegation::where('delegator_user_id', $user->id)
        ->where('is_active', true)
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->orderByDesc('created_at')
        ->get()
        ->first(function (Delegation $delegation) use ($blueprintCategoryId, $stageTypeId) {
            return match ($delegation->scope_type) {
                DelegationScopeType::ALL => true,
                DelegationScopeType::BLUEPRINT_CATEGORY => $delegation->blueprint_category_id === $blueprintCategoryId,
                DelegationScopeType::STAGE_TYPE => $delegation->stage_type_id === $stageTypeId,
                DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE =>
                    $delegation->blueprint_category_id === $blueprintCategoryId
                    && $delegation->stage_type_id === $stageTypeId,
            };
        })
        ?->delegate;
}

private function resolveSimpleOutOfOfficeDelegate(User $user): ?User
{
    if ($this->isOutOfOffice($user) && $user->out_of_office_delegate_user_id) {
        return $user->outOfOfficeDelegate;
    }

    return null;
}
```

**Rules from `coding-standards.md`:**
- Enum Usage: compare `DelegationScopeType` enum cases, never integers.
- No Redis caching: resolution must reflect the current clock.

**Test cases:**
1. User has `DelegationScopeType::BLUEPRINT_CATEGORY` matching task category → returns delegate.
2. User has scoped delegation for wrong category but simple OOF delegate set → returns OOF delegate.
3. User has no delegation → returns `null`.

---

### 2. `AssignmentResolutionService` Integration

**One-line summary:** Replace simple OOF resolution with context-aware delegation resolution for both stage and sub-stage assignments.

**Key decisions:**
- Derive `blueprint_category_id` from `$task->blueprint->category_id`.
- For stages, derive `stage_type_id` from `$blueprintStage->stage_type_id`.
- For sub-stages, derive `stage_type_id` from `$blueprintSubStage->stage->stage_type_id` (parent stage).
- Eager-load relationships in `TaskService::launch()` and `StageLifecycleService` to avoid N+1.
- Keep `delegated_from_user_id` populated whenever the effective user differs from the resolved user.

**Files to edit:**
- `app/Modules/Task/Services/AssignmentResolutionService.php`
- `app/Modules/Task/Services/TaskService.php` (eager-load `blueprint.category` and `subStages.stage.stageType`)
- `app/Modules/Task/Services/StageLifecycleService.php` (eager-load where sub-stage assignments are resolved)

**Code snippet — stage assignment loop:**

```php
$blueprintCategoryId = $task->blueprint->category_id;
$stageTypeId = $blueprintStage->stage_type_id;

foreach ($resolvedUsers as $i => $resolvedUser) {
    $effectiveUser = $this->iamPolicy->resolveDelegateForAssignment(
        $resolvedUser,
        $blueprintCategoryId,
        $stageTypeId,
    ) ?? $resolvedUser;

    $delegatedFrom = $effectiveUser->id !== $resolvedUser->id ? $resolvedUser->id : null;
    // ... create TaskStageAssignment with delegated_from_user_id ...
}
```

**Code snippet — sub-stage assignment loop:**

```php
$blueprintCategoryId = $task->blueprint->category_id;
$stageTypeId = $blueprintSubStage->stage->stage_type_id;

foreach ($resolvedUsers as $i => $resolvedUser) {
    $effectiveUser = $this->iamPolicy->resolveDelegateForAssignment(
        $resolvedUser,
        $blueprintCategoryId,
        $stageTypeId,
    ) ?? $resolvedUser;

    $delegatedFrom = $effectiveUser->id !== $resolvedUser->id ? $resolvedUser->id : null;
    // ... create TaskStageAssignment with delegated_from_user_id ...
}
```

**Eager-load update in `TaskService::launch()`:**

```php
$stages = $blueprint->stages()
    ->with(['subStages.stage.stageType'])
    ->orderBy('sequence_order')
    ->get();
```

**Rules from `coding-standards.md`:**
- Eager Loading: verify relationships are `with()`-loaded before resource access.
- No cross-module ORM joins: `AssignmentResolutionService` calls `IamPolicy` service method rather than importing `Delegation` model.

**Test cases:**
1. Blueprint category-scoped delegation → assignment `user_id` = delegate, `delegated_from_user_id` = original occupant.
2. Non-matching scoped delegation + simple OOF delegate → assignment routes to OOF delegate.
3. No delegation → assignment `user_id` = original occupant, `delegated_from_user_id` = `null`.

---

### 3. Delegation Scope Validation

**One-line summary:** Form requests enforce that `blueprint_category_id` and `stage_type_id` are present/valid exactly when the scope type requires them.

**Key decisions:**
- Use `required_if:scope_type,2,4` and `required_if:scope_type,3,4` style rules.
- Validate IDs against `blueprint_categories.public_id` and `stage_types.public_id`.
- Add `prohibited_if:scope_type,1` (or simply ignore) for scope `ALL`.
- Service layer re-validates as a safety net and throws `DelegationScopeMismatchException`.

**Files to edit:**
- `app/Modules/Iam/Requests/StoreDelegationRequest.php`
- `app/Modules/Iam/Requests/UpdateDelegationRequest.php`
- `app/Modules/Iam/Services/DelegationService.php`
- `app/Modules/Iam/Exceptions/DelegationScopeMismatchException.php`

**Code snippet — `StoreDelegationRequest::rules()`:**

```php
use App\Enums\DelegationScopeType;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;

public function rules(): array
{
    return [
        'delegator_user_id' => ['nullable', 'exists:users,public_id'],
        'delegate_user_id' => ['required', 'exists:users,public_id'],
        'starts_at' => ['required', 'date', 'after:now'],
        'ends_at' => ['required', 'date', 'after:starts_at'],
        'scope_type' => ['required', Rule::enum(DelegationScopeType::class)],
        'blueprint_category_id' => [
            'nullable',
            'string',
            Rule::requiredIf(fn () => in_array(
                (int) $this->input('scope_type'),
                [DelegationScopeType::BLUEPRINT_CATEGORY->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                true
            )),
            Rule::exists(BlueprintCategory::class, 'public_id'),
        ],
        'stage_type_id' => [
            'nullable',
            'string',
            Rule::requiredIf(fn () => in_array(
                (int) $this->input('scope_type'),
                [DelegationScopeType::STAGE_TYPE->value, DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE->value],
                true
            )),
            Rule::exists(StageType::class, 'public_id'),
        ],
    ];
}
```

> **Note:** `Rule::requiredIf` accepts a closure. Alternatively use `required_if:scope_type,2,4` string rules if the enum integer values are stable. Prefer closure + enum cases to avoid magic numbers.

**Code snippet — `DelegationService::validateScopeFields()`:**

```php
use App\Enums\DelegationScopeType;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Iam\Exceptions\DelegationScopeMismatchException;

private function validateScopeFields(array $data, DelegationScopeType $scopeType): array
{
    $categoryId = null;
    $stageTypeId = null;

    switch ($scopeType) {
        case DelegationScopeType::ALL:
            break;
        case DelegationScopeType::BLUEPRINT_CATEGORY:
            $categoryId = $this->resolveBlueprintCategoryId($data['blueprint_category_id'] ?? null);
            break;
        case DelegationScopeType::STAGE_TYPE:
            $stageTypeId = $this->resolveStageTypeId($data['stage_type_id'] ?? null);
            break;
        case DelegationScopeType::BLUEPRINT_CATEGORY_AND_STAGE_TYPE:
            $categoryId = $this->resolveBlueprintCategoryId($data['blueprint_category_id'] ?? null);
            $stageTypeId = $this->resolveStageTypeId($data['stage_type_id'] ?? null);
            break;
    }

    return [$categoryId, $stageTypeId];
}

private function resolveBlueprintCategoryId(?string $publicId): ?int
{
    if ($publicId === null) {
        throw new DelegationScopeMismatchException('blueprint_category_id is required for this scope.');
    }

    $id = BlueprintCategory::where('public_id', $publicId)->value('id');

    if ($id === null) {
        throw new DelegationScopeMismatchException('Invalid blueprint_category_id.');
    }

    return $id;
}

private function resolveStageTypeId(?string $publicId): ?int
{
    if ($publicId === null) {
        throw new DelegationScopeMismatchException('stage_type_id is required for this scope.');
    }

    $id = StageType::where('public_id', $publicId)->value('id');

    if ($id === null) {
        throw new DelegationScopeMismatchException('Invalid stage_type_id.');
    }

    return $id;
}
```

**Rules from `coding-standards.md`:**
- Enum Usage: `DelegationScopeType` cases in validation and service logic.
- Error Handling: log warnings via `Log::channel('iam')` for mismatches.

**Test cases:**
1. `scope_type=2` with valid `blueprint_category_id` → 201 created.
2. `scope_type=2` without `blueprint_category_id` → 422.
3. `scope_type=1` with `blueprint_category_id` provided → 422 (or ignored, depending on rule chosen; document the chosen behavior).

---

### 4. Auto-Expiry Command & Job

**One-line summary:** A scheduled command dispatches per-tenant queued jobs that deactivate expired delegations and emit `DelegationExpired` events.

**Key decisions:**
- Follow Spec 007 pattern: `ExpireDelegationsCommand` iterates active tenants and dispatches `ExpireDelegationsJob`.
- `ExpireDelegationsJob` initializes tenancy, runs `DelegationExpiryService::expire()`, logs result.
- `DelegationExpiryService` uses `chunkById` to process large volumes and wraps each batch in `DB::transaction()`.
- Idempotent: skip already-inactive rows.

**Files to edit:**
- `app/Modules/Iam/Commands/ExpireDelegationsCommand.php`
- `app/Modules/Iam/Jobs/ExpireDelegationsJob.php`
- `app/Modules/Iam/Services/DelegationExpiryService.php`
- `routes/console.php`

**Code snippet — `ExpireDelegationsCommand`:**

```php
namespace App\Modules\Iam\Commands;

use App\Models\Tenant;
use App\Modules\Iam\Jobs\ExpireDelegationsJob;
use Illuminate\Console\Command;

class ExpireDelegationsCommand extends Command
{
    protected $signature = 'iam:expire-delegations';

    protected $description = 'Dispatch delegation expiry jobs for all active tenants';

    public function handle(): int
    {
        $tenants = Tenant::where('is_active', true)->get();

        foreach ($tenants as $tenant) {
            ExpireDelegationsJob::dispatch($tenant->slug);
        }

        $this->info("Dispatched delegation expiry for {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
```

**Code snippet — `ExpireDelegationsJob`:**

```php
namespace App\Modules\Iam\Jobs;

use App\Modules\Iam\Services\DelegationExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ExpireDelegationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(public string $tenantSlug) {}

    public function handle(DelegationExpiryService $expiryService): void
    {
        tenancy()->initialize($this->tenantSlug);

        $expiredCount = $expiryService->expire();

        Log::channel('iam')->info('Delegations expired', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'delegation.expire',
            'entity_type' => 'delegation',
            'entity_id' => null,
            'performed_by' => 'system',
            'expired_count' => $expiredCount,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('iam')->error('Delegation expiry job failed', [
            'tenant_slug' => $this->tenantSlug,
            'action' => 'delegation.expire_failed',
            'entity_type' => 'delegation',
            'entity_id' => null,
            'performed_by' => 'system',
            'error' => $exception->getMessage(),
        ]);
    }
}
```

**Code snippet — `DelegationExpiryService::expire()`:**

```php
namespace App\Modules\Iam\Services;

use App\Modules\Iam\Events\DelegationExpired;
use App\Modules\Iam\Models\Delegation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelegationExpiryService
{
    public function expire(): int
    {
        $count = 0;

        try {
            Delegation::where('is_active', true)
                ->where('ends_at', '<', now())
                ->chunkById(500, function ($delegations) use (&$count) {
                    DB::transaction(function () use ($delegations, &$count) {
                        foreach ($delegations as $delegation) {
                            $delegation->update(['is_active' => false]);
                            event(new DelegationExpired($delegation));
                            $count++;
                        }
                    });
                });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to expire delegations', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'delegation.expire',
                'entity_type' => 'delegation',
                'entity_id' => null,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $count;
    }
}
```

**Scheduler registration in `routes/console.php`:**

```php
Schedule::command('iam:expire-delegations')->everyMinute();
```

**Rules from `coding-standards.md`:**
- Database Transactions: multi-write expiry loop wrapped in `DB::transaction()` per chunk.
- Queue Jobs: `ShouldQueue`, `tries=3`, `backoff=[30,60,120]`, tenant slug in payload.
- Domain Events: `DelegationExpired` implements `ShouldDispatchAfterCommit`.
- Chunk for Bulk Operations: `chunkById(500)` to avoid loading millions of rows.

**Test cases:**
1. Expired delegation (`ends_at` in past) → command sets `is_active=false` and emits `DelegationExpired`.
2. Active delegation (`ends_at` in future) → command leaves it active.

---

### 5. `DelegationExpired` Domain Event

**One-line summary:** Event fired after a delegation is auto-expired; recorded by Audit module.

**Key decisions:**
- Implements `ShouldDispatchAfterCommit` + `ProvidesAuditData`.
- `AuditEntityType::Delegation` already exists (value 22).

**Files to edit:** `app/Modules/Iam/Events/DelegationExpired.php`

**Code snippet:**

```php
namespace App\Modules\Iam\Events;

use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Iam\Models\Delegation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DelegationExpired implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Delegation $delegation) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'delegation.expired',
            entityType: AuditEntityType::Delegation,
            entityId: $this->delegation->id,
            entityPublicId: $this->delegation->public_id,
            user: $this->delegation->delegator,
            payload: [
                'delegator_user_id' => $this->delegation->delegator_user_id,
                'delegate_user_id' => $this->delegation->delegate_user_id,
                'scope_type' => $this->delegation->scope_type->value,
            ],
        );
    }
}
```

**Rules from `coding-standards.md`:**
- Domain Events Must Use `ShouldDispatchAfterCommit`.

---

### 6. Active Delegations Endpoint

**One-line summary:** `GET /api/v1/iam/delegations/active` returns currently active delegations with cursor pagination and filters.

**Key decisions:**
- Use cursor pagination ordered by `ends_at ASC, id ASC`. Because cursor pagination requires an `orderBy('id')`, use composite ordering `(ends_at, id)` and cursor on `id` after filtering/sorting.
- Alternatively, order by `id` only and apply `ends_at` filter; choose whichever is simpler and document it.
- Recommended: filter `is_active=true` + `starts_at <= now()` + `ends_at >= now()` then `orderBy('id')` and `cursorPaginate`.
- Support filters by `delegator_user_id`, `delegate_user_id`, `blueprint_category_id`, `stage_type_id` (all `public_id`).
- Capability: `iam.manage_users` **or** `iam.view_delegations`.

**Files to edit:**
- `app/Modules/Iam/Controllers/DelegationController.php`
- `app/Modules/Iam/Requests/ListActiveDelegationsRequest.php`
- `routes/api/v1/iam.php`

**Code snippet — `ListActiveDelegationsRequest`:**

```php
namespace App\Modules\Iam\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListActiveDelegationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delegator_user_id' => ['nullable', 'string', 'exists:users,public_id'],
            'delegate_user_id' => ['nullable', 'string', 'exists:users,public_id'],
            'blueprint_category_id' => ['nullable', 'string', 'exists:blueprint_categories,public_id'],
            'stage_type_id' => ['nullable', 'string', 'exists:stage_types,public_id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

**Code snippet — `DelegationController::active()`:**

```php
use App\Modules\Iam\Requests\ListActiveDelegationsRequest;
use Illuminate\Http\Resources\Json\JsonResource;

public function active(ListActiveDelegationsRequest $request): JsonResource
{
    $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

    $query = Delegation::with(['delegator', 'delegate'])
        ->where('is_active', true)
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now());

    if ($request->filled('delegator_user_id')) {
        $query->whereHas('delegator', fn ($q) => $q->where('public_id', $request->input('delegator_user_id')));
    }

    if ($request->filled('delegate_user_id')) {
        $query->whereHas('delegate', fn ($q) => $q->where('public_id', $request->input('delegate_user_id')));
    }

    if ($request->filled('blueprint_category_id')) {
        $categoryId = BlueprintCategory::where('public_id', $request->input('blueprint_category_id'))->value('id');
        $query->where('blueprint_category_id', $categoryId);
    }

    if ($request->filled('stage_type_id')) {
        $stageTypeId = StageType::where('public_id', $request->input('stage_type_id'))->value('id');
        $query->where('stage_type_id', $stageTypeId);
    }

    return DelegationResource::collection(
        $query->orderBy('id')->cursorPaginate($request->integer('per_page', 15))
    );
}
```

**Route registration:**

```php
Route::middleware(['capability:iam.manage_users,iam.view_delegations'])->group(function () {
    Route::get('delegations', [DelegationController::class, 'index']);
    Route::get('delegations/active', [DelegationController::class, 'active']);
    Route::post('delegations', [DelegationController::class, 'store']);
    Route::get('delegations/{delegation}', [DelegationController::class, 'show']);
    Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);
});
```

> **Note:** `capability:iam.manage_users,iam.view_delegations` syntax depends on the existing `RequireCapability` middleware. If it only supports a single capability, create a nested group or update the middleware to accept a comma-separated list. Use the existing project pattern.

**Rules from `coding-standards.md`:**
- Pagination: cursor pagination on list endpoint.
- Rate Limiting: `RateLimits::LIST` on read endpoints.
- Eager Loading: `with(['delegator', 'delegate'])`.

**Test cases:**
1. Two delegations, one expired → `GET /delegations/active` returns only the active one.
2. Filter by `delegator_user_id` → returns only matching active delegations.

---

### 7. Existing `GET /api/v1/iam/delegations` Enhancement

**One-line summary:** Add `active_now` boolean filter to the existing index.

**Files to edit:** `app/Modules/Iam/Controllers/DelegationController.php`

**Code snippet:**

```php
if ($request->boolean('active_now')) {
    $query->where('is_active', true)
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now());
}
```

**Rules from `coding-standards.md`:**
- Keep the existing non-paginated behavior or convert to cursor pagination if the spec requires it. This spec says cursor pagination for `active_now=true`; the base index can remain unpaginated if it was already unpaginated, but align with spec acceptance criteria.

---

### 8. Capability Seeding

**One-line summary:** Add `iam.view_delegations` to `CapabilitySeeder` and ensure tenant admin provisioning grants it.

**Files to edit:** `database/seeders/CapabilitySeeder.php`

**Code snippet:**

```php
['key' => 'iam.view_delegations', 'name_ar' => 'عرض التفويضات', 'name_en' => 'View Delegations', 'description' => 'Can view active delegations in the organization.'],
```

Then ensure the tenant provisioning seeder grants this capability to the initial tenant admin position (same pattern as other `iam.manage_*` capabilities). If provisioning only grants `iam.manage_*`, update it to also grant `iam.view_delegations`.

**Rules from `coding-standards.md`:**
- Capabilities are named with dot notation.

---

### 9. Exception & Translation

**One-line summary:** Add a domain exception for scope mismatches and bilingual translation strings.

**Files to edit:**
- `app/Modules/Iam/Exceptions/DelegationScopeMismatchException.php`
- `lang/en/iam.php`
- `lang/ar/iam.php`
- `bootstrap/app.php` (if not auto-handled by `DomainException`)

**Code snippet:**

```php
namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class DelegationScopeMismatchException extends DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('iam.exceptions.delegation_scope_mismatch'));
    }
}
```

**Translation entries:**

```php
// lang/en/iam.php
'delegation_scope_mismatch' => 'The delegation scope is missing required fields or contains invalid IDs.',

// lang/ar/iam.php
'delegation_scope_mismatch' => 'نطاق التفويض يفتقر إلى الحقول المطلوبة أو يحتوي على معرفات غير صالحة.',
```

`bootstrap/app.php` already renders all `DomainException` subclasses via `$exceptions->renderable(fn (DomainException $e) => $e->render());`, so no additional registration is needed.

---

### 10. `Delegation` Model Relationships

**One-line summary:** Add read-only relationships to `BlueprintCategory` and `StageType` for eager loading in resources.

**Files to edit:** `app/Modules/Iam/Models/Delegation.php`

**Code snippet:**

```php
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Models\StageType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function blueprintCategory(): BelongsTo
{
    return $this->belongsTo(BlueprintCategory::class, 'blueprint_category_id');
}

public function stageType(): BelongsTo
{
    return $this->belongsTo(StageType::class, 'stage_type_id');
}
```

> **Note:** These are read-only relationships. No DB-level FK is added; `003` migration left the columns as nullable integers without FK constraints.

---

## Execution Order

1. **Update spec open questions** — mark resolved in `specs/016-delegation-oof/spec.md`.
2. **Add `iam.view_delegations` capability** — edit `database/seeders/CapabilitySeeder.php` and tenant provisioning grant logic.
3. **Add exception + translations** — create `DelegationScopeMismatchException` and lang entries.
4. **Add model relationships** — `Delegation::blueprintCategory()` and `Delegation::stageType()`.
5. **Update form request validation** — `StoreDelegationRequest` and `UpdateDelegationRequest`.
6. **Update `DelegationService`** — normalize scope fields, throw `DelegationScopeMismatchException`.
7. **Add `IamPolicy::resolveDelegateForAssignment()`** — scoped delegation + OOF fallback.
8. **Update `AssignmentResolutionService`** — route assignments through new policy method.
9. **Add expiry service, command, job, event** — `DelegationExpiryService`, `ExpireDelegationsCommand`, `ExpireDelegationsJob`, `DelegationExpired`.
10. **Add active delegations endpoint** — controller method, form request, route.
11. **Enhance existing index** — add `active_now` filter.
12. **Write feature tests** — expiry, scoped routing, active endpoint, validation, ABAC.
13. **Regenerate OpenAPI** — run Scramble and commit `openapi/openapi.json`.
14. **Run full test suite** — `php artisan test`.
15. **Run Pint** — `vendor/bin/pint --dirty --format agent`.

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/iam/delegations` | Sanctum | `iam.manage_users` or `iam.view_delegations` | List delegations; optional `active_now` filter. |
| GET | `/api/v1/iam/delegations/active` | Sanctum | `iam.manage_users` or `iam.view_delegations` | Cursor-paginated currently active delegations with filters. |
| POST | `/api/v1/iam/delegations` | Sanctum | `iam.manage_users` | Create delegation; scope fields validated conditionally. |
| PUT | `/api/v1/iam/delegations/{delegation}` | Sanctum | `iam.manage_users` | Update delegation fields (dates, scope type, scope IDs). |
| GET | `/api/v1/iam/delegations/{delegation}` | Sanctum | `iam.manage_users` or `iam.view_delegations` | Show single delegation. |
| POST | `/api/v1/iam/delegations/{delegation}/revoke` | Sanctum | `iam.manage_users` or self-delegator | Revoke delegation. |

### Request Examples

**Create scoped delegation:**
```json
POST /api/v1/iam/delegations
{
  "delegate_user_id": "0190abcd-...",
  "starts_at": "2026-07-01T08:00:00Z",
  "ends_at": "2026-07-10T17:00:00Z",
  "scope_type": 2,
  "blueprint_category_id": "0190cdef-..."
}
```

**List active delegations:**
```
GET /api/v1/iam/delegations/active?delegator_user_id=0190abcd-...&per_page=15
```

### Response Examples

**Cursor-paginated active delegations:**
```json
{
  "data": [
    {
      "public_id": "0190aaaa-...",
      "delegator": { "public_id": "...", "name_ar": "...", "name_en": "..." },
      "delegate": { "public_id": "...", "name_ar": "...", "name_en": "..." },
      "starts_at": "2026-07-01T08:00:00+00:00",
      "ends_at": "2026-07-10T17:00:00+00:00",
      "scope_type": 2,
      "is_active": true,
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "next_cursor": "eyJpZCI6MTAwfQ==",
  "has_more": false
}
```

---

## What to Test Manually

1. **Happy path — scoped routing:** Create a category-scoped delegation, launch a task in that category, verify assignment is routed to delegate with `delegated_from_user_id` set.
2. **Fallback — simple OOF:** Create a non-matching scoped delegation and set a simple OOF delegate; launch a task; verify it routes to the OOF delegate.
3. **No delegation:** Launch a task for a user with no delegation and no OOF delegate; verify assignment stays with original user and `delegated_from_user_id` is null.
4. **Most-recent wins:** Create two overlapping `ALL` delegations with different delegates; verify the most recent one is used.
5. **Auto-expiry:** Create a delegation ending in the past, run `php artisan iam:expire-delegations`, verify `is_active=false` and an audit event is recorded.
6. **Active endpoint filters:** Use `GET /delegations/active` with each filter and verify results.
7. **Validation:** Attempt to create a `BLUEPRINT_CATEGORY` delegation without `blueprint_category_id`; expect 422.
8. **ABAC:** Authenticate as a non-admin user without `iam.view_delegations`; expect 403 on `GET /delegations/active`.
9. **Rate limiting:** Hit `POST /delegations` > 30 times in one minute; expect 429.
10. **Concurrent expiry safety:** Run two expiry commands simultaneously; verify no errors and idempotent deactivation.
