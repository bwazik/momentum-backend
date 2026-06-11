# Plan: Blueprint Engine

> **Spec:** 004-blueprint-engine
> **Date:** 2026-06-10
> **Status:** approved

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| Duplicate blueprint: copy category_id? | **Yes, copy it.** | Category is a grouping mechanism; duplicating without category would orphan the copy. |
| Blueprint lock: DB column or computed? | **DB column `is_locked`.** | Performance + ability to lock without tasks for governance. |
| sla_policies module location? | **Blueprint module.** | Template definitions, not runtime timers. Spec 007 may add runtime SLA tables later. |
| Branching transitions allowed? | **Yes.** | Multiple `to_stage` from same `from_stage` allowed; task module chooses at runtime. |
| Cascade delete on stage deletion? | **Yes.** | Sub-stages and transitions referencing the stage are cascade-deleted. |
| sequence_order type? | **Integer (smallint).** | Reorder endpoint handles insertions via bulk update. |
| Category nesting? | **No — flat list.** | MVP simplicity; hierarchy deferred to V2. |
| assignment_type: authority grade? | **No.** | ERD only supports specific_position, department_head, manual_at_launch. Authority grade used for escalation analytics. |
| escalation_position_id validation? | **Soft validation.** | Warn if inactive but allow for flexibility. |
| System-defined stage types: per tenant or global? | **Per tenant on provisioning.** | Tenants can customize display order and deactivate unused ones. |

---

## Technical Approach

Build the Blueprint module as a self-contained Laravel module under `app/Modules/Blueprint/` following the established Organization module pattern. Seven tables, six enums, one service per entity, one controller per entity, API Resources for all responses. All mutations protected by `RequireCapability` middleware. Blueprint lock enforced at service layer before any mutation.

---

## Affected Modules / Files

### New Files (to create)

| File | Purpose |
|------|---------|
| `app/Modules/Blueprint/Enums/BlueprintScope.php` | Organization / Department scope |
| `app/Modules/Blueprint/Enums/AssignmentType.php` | SpecificPosition / DepartmentHead / ManualAtLaunch |
| `app/Modules/Blueprint/Enums/AssignmentCardinality.php` | Single / Multiple assignees |
| `app/Modules/Blueprint/Enums/CompletionRule.php` | AnyAssignee / AllAssignees / LeadAssignee |
| `app/Modules/Blueprint/Enums/SlaUnit.php` | Hours / Days |
| `app/Modules/Blueprint/Enums/TransitionType.php` | Advance / Return |
| `app/Modules/Blueprint/Models/BlueprintCategory.php` | Category entity |
| `app/Modules/Blueprint/Models/StageType.php` | Stage type entity |
| `app/Modules/Blueprint/Models/SlaPolicy.php` | SLA policy entity |
| `app/Modules/Blueprint/Models/Blueprint.php` | Blueprint entity |
| `app/Modules/Blueprint/Models/BlueprintStage.php` | Stage entity |
| `app/Modules/Blueprint/Models/BlueprintSubStage.php` | Sub-stage entity |
| `app/Modules/Blueprint/Models/BlueprintTransition.php` | Transition entity |
| `app/Modules/Blueprint/Services/BlueprintCategoryService.php` | Category CRUD |
| `app/Modules/Blueprint/Services/StageTypeService.php` | Stage type CRUD |
| `app/Modules/Blueprint/Services/SlaPolicyService.php` | SLA policy CRUD |
| `app/Modules/Blueprint/Services/BlueprintService.php` | Blueprint CRUD + duplicate + activate |
| `app/Modules/Blueprint/Services/BlueprintStageService.php` | Stage CRUD + reorder |
| `app/Modules/Blueprint/Services/BlueprintSubStageService.php` | Sub-stage CRUD + reorder |
| `app/Modules/Blueprint/Services/BlueprintTransitionService.php` | Transition CRUD |
| `app/Modules/Blueprint/Controllers/BlueprintCategoryController.php` | Category API |
| `app/Modules/Blueprint/Controllers/StageTypeController.php` | Stage type API |
| `app/Modules/Blueprint/Controllers/SlaPolicyController.php` | SLA policy API |
| `app/Modules/Blueprint/Controllers/BlueprintController.php` | Blueprint API |
| `app/Modules/Blueprint/Controllers/BlueprintStageController.php` | Stage API |
| `app/Modules/Blueprint/Controllers/BlueprintSubStageController.php` | Sub-stage API |
| `app/Modules/Blueprint/Controllers/BlueprintTransitionController.php` | Transition API |
| `app/Modules/Blueprint/Requests/StoreBlueprintCategoryRequest.php` | Category validation |
| `app/Modules/Blueprint/Requests/UpdateBlueprintCategoryRequest.php` | Category validation |
| `app/Modules/Blueprint/Requests/StoreStageTypeRequest.php` | Stage type validation |
| `app/Modules/Blueprint/Requests/UpdateStageTypeRequest.php` | Stage type validation |
| `app/Modules/Blueprint/Requests/StoreSlaPolicyRequest.php` | SLA policy validation |
| `app/Modules/Blueprint/Requests/UpdateSlaPolicyRequest.php` | SLA policy validation |
| `app/Modules/Blueprint/Requests/StoreBlueprintRequest.php` | Blueprint validation |
| `app/Modules/Blueprint/Requests/UpdateBlueprintRequest.php` | Blueprint validation |
| `app/Modules/Blueprint/Requests/StoreBlueprintStageRequest.php` | Stage validation |
| `app/Modules/Blueprint/Requests/UpdateBlueprintStageRequest.php` | Stage validation |
| `app/Modules/Blueprint/Requests/StoreBlueprintSubStageRequest.php` | Sub-stage validation |
| `app/Modules/Blueprint/Requests/UpdateBlueprintSubStageRequest.php` | Sub-stage validation |
| `app/Modules/Blueprint/Requests/StoreBlueprintTransitionRequest.php` | Transition validation |
| `app/Modules/Blueprint/Requests/UpdateBlueprintTransitionRequest.php` | Transition validation |
| `app/Modules/Blueprint/Requests/ReorderStagesRequest.php` | Bulk reorder validation |
| `app/Modules/Blueprint/Requests/ReorderSubStagesRequest.php` | Bulk reorder validation |
| `app/Modules/Blueprint/Resources/BlueprintCategoryResource.php` | Category JSON shape |
| `app/Modules/Blueprint/Resources/StageTypeResource.php` | Stage type JSON shape |
| `app/Modules/Blueprint/Resources/SlaPolicyResource.php` | SLA policy JSON shape |
| `app/Modules/Blueprint/Resources/BlueprintResource.php` | Blueprint JSON shape |
| `app/Modules/Blueprint/Resources/BlueprintStageResource.php` | Stage JSON shape |
| `app/Modules/Blueprint/Resources/BlueprintSubStageResource.php` | Sub-stage JSON shape |
| `app/Modules/Blueprint/Resources/BlueprintTransitionResource.php` | Transition JSON shape |
| `app/Modules/Blueprint/Events/BlueprintCategoryCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintCategoryUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/StageTypeCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/StageTypeUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintActivated.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintDeactivated.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintLocked.php` | Domain event |
| `app/Modules/Blueprint/Events/BlueprintDuplicated.php` | Domain event |
| `app/Modules/Blueprint/Events/StageCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/StageUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/StageDeleted.php` | Domain event |
| `app/Modules/Blueprint/Events/StageReordered.php` | Domain event |
| `app/Modules/Blueprint/Events/SubStageCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/SubStageUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/SubStageDeleted.php` | Domain event |
| `app/Modules/Blueprint/Events/SubStageReordered.php` | Domain event |
| `app/Modules/Blueprint/Events/SlaPolicyCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/SlaPolicyUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/SlaPolicyDeleted.php` | Domain event |
| `app/Modules/Blueprint/Events/TransitionCreated.php` | Domain event |
| `app/Modules/Blueprint/Events/TransitionUpdated.php` | Domain event |
| `app/Modules/Blueprint/Events/TransitionDeleted.php` | Domain event |
| `app/Modules/Blueprint/Exceptions/BlueprintLockedException.php` | Lock exception |
| `app/Modules/Blueprint/Exceptions/InvalidStageSequenceException.php` | Sequence exception |
| `app/Modules/Blueprint/Exceptions/InvalidTransitionException.php` | Transition exception |
| `app/Modules/Blueprint/Exceptions/SlaPolicyInUseException.php` | In-use exception |
| `app/Modules/Blueprint/Exceptions/StageTypeInUseException.php` | In-use exception |
| `app/Modules/Blueprint/Exceptions/BlueprintCategoryInUseException.php` | In-use exception |
| `app/Modules/Blueprint/Exceptions/InvalidBlueprintScopeException.php` | Scope exception |
| `database/tenant/2026_06_10_000001_create_blueprint_categories_table.php` | Migration |
| `database/tenant/2026_06_10_000002_create_stage_types_table.php` | Migration |
| `database/tenant/2026_06_10_000003_create_sla_policies_table.php` | Migration |
| `database/tenant/2026_06_10_000004_create_blueprints_table.php` | Migration |
| `database/tenant/2026_06_10_000005_create_blueprint_stages_table.php` | Migration |
| `database/tenant/2026_06_10_000006_create_blueprint_sub_stages_table.php` | Migration |
| `database/tenant/2026_06_10_000007_create_blueprint_transitions_table.php` | Migration |
| `routes/api/v1/blueprints.php` | Routes |
| `tests/Feature/Api/V1/Blueprint/BlueprintCategoryTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/StageTypeTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/SlaPolicyTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/BlueprintTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/BlueprintStageTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/BlueprintSubStageTest.php` | Feature tests |
| `tests/Feature/Api/V1/Blueprint/BlueprintTransitionTest.php` | Feature tests |

### Modified Files (to edit)

| File | Change |
|------|--------|
| `config/logging.php` | Add `blueprint` channel |
| `bootstrap/app.php` | Register blueprint exceptions |
| `database/seeders/TenantDatabaseSeeder.php` | Seed default stage types on tenant provisioning |
| `routes/api.php` | Include `blueprints.php` route file |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Create 6 enum classes in `app/Modules/Blueprint/Enums/`. Each maps to a TINYINT in the DB.

**Files:**
- `app/Modules/Blueprint/Enums/BlueprintScope.php`
- `app/Modules/Blueprint/Enums/AssignmentType.php`
- `app/Modules/Blueprint/Enums/AssignmentCardinality.php`
- `app/Modules/Blueprint/Enums/CompletionRule.php`
- `app/Modules/Blueprint/Enums/SlaUnit.php`
- `app/Modules/Blueprint/Enums/TransitionType.php`

**Code snippet (pattern):**
```php
<?php

namespace App\Modules\Blueprint\Enums;

enum BlueprintScope: int
{
    case Organization = 1;
    case Department = 2;
}
```

**Rules:** `coding-standards.md` — Enum Usage. Use `Rule::enum(ClassName::class)` in Form Requests.

---

### 2. Migrations

**One-line summary:** Create 7 migrations in `database/tenant/`. All use `bigIncrements`, `public_id` (UUID v7), proper FKs, and indexes.

**Key decisions:**
- `blueprints.is_locked` defaults to `false`
- `blueprints.scope` stores TINYINT (1=org, 2=dept)
- `blueprint_stages.sequence_order` is `smallint` with unique composite index `(blueprint_id, sequence_order)`
- `blueprint_sub_stages.sequence_order` is `smallint` with unique composite index `(blueprint_stage_id, sequence_order)`
- `blueprint_transitions` has no `public_id` (per ERD)
- Soft deletes on categories, stage types, SLA policies, blueprints
- No soft deletes on stages, sub-stages, transitions (they are deleted within draft blueprints only)

**Code snippet (pattern — blueprint_stages):**
```php
Schema::create('blueprint_stages', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('blueprint_id')->constrained('blueprints')->cascadeOnDelete();
    $table->foreignId('stage_type_id')->constrained('stage_types');
    $table->foreignId('sla_policy_id')->nullable()->constrained('sla_policies')->nullOnDelete();
    $table->string('name_en');
    $table->string('name_ar');
    $table->text('description_en')->nullable();
    $table->text('description_ar')->nullable();
    $table->smallInteger('sequence_order');
    $table->unsignedTinyInteger('assignment_type');
    $table->foreignId('assigned_position_id')->nullable()->constrained('positions')->nullOnDelete();
    $table->foreignId('assigned_department_id')->nullable()->constrained('departments')->nullOnDelete();
    $table->unsignedTinyInteger('assignment_cardinality')->default(1);
    $table->unsignedTinyInteger('completion_rule')->default(1);
    $table->foreignId('escalation_position_id')->nullable()->constrained('positions')->nullOnDelete();
    $table->timestamps();
    $table->unique(['blueprint_id', 'sequence_order']);
});
```

**Rules:** `coding-standards.md` — Migrations. No tenant_id columns. Use `constrained()` for FKs.

---

### 3. Models

**One-line summary:** Extend `TenantModel`, use `#[Fillable]`, `SoftDeletes` where applicable, define casts and relationships.

**Key decisions:**
- All models use `HasFactory` and `SoftDeletes` (except stages/sub-stages/transitions)
- Casts method returns enum classes for TINYINT columns
- Relationships use `BelongsTo` / `HasMany` with correct return types
- `scopeActive()` local scope on all models with `is_active`

**Code snippet (pattern — Blueprint):**
```php
<?php

namespace App\Modules\Blueprint\Models;

use App\Models\TenantModel;
use App\Modules\Blueprint\Enums\BlueprintScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'name_en', 'name_ar', 'description_en', 'description_ar', 'scope', 'department_id', 'is_active', 'created_by_user_id'])]
class Blueprint extends TenantModel
{
    use HasFactory, SoftDeletes;

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlueprintCategory::class, 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Organization\Models\Department::class, 'department_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(BlueprintStage::class)->orderBy('sequence_order');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(BlueprintTransition::class);
    }

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'is_active' => 'boolean',
            'scope' => BlueprintScope::class,
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

**Rules:** `coding-standards.md` — Models. No tenant_id. Use `casts()` method.

---

### 4. Services

**One-line summary:** One service per entity. All services use `try/catch` with `Log::channel('blueprint')`, `DB::transaction()` for multi-write operations, and emit domain events.

**Key decisions:**
- `BlueprintService` handles create, update, duplicate, activate, deactivate, delete
- Duplicate uses `DB::transaction()` to copy blueprint + stages + sub-stages + transitions atomically
- Lock check: `if ($blueprint->is_locked) throw new BlueprintLockedException`
- Activate checks: `is_locked = false` AND at least one stage exists
- Bilingual fallback: `name_en` = `name_en ?? name_ar`; `description_en` = `description_en ?? description_ar`

**Code snippet (pattern — BlueprintService::duplicate):**
```php
public function duplicate(Blueprint $blueprint, User $user): Blueprint
{
    try {
        return DB::transaction(function () use ($blueprint, $user) {
            $newBlueprint = Blueprint::create([
                'category_id' => $blueprint->category_id,
                'name_en' => 'Copy of ' . $blueprint->name_en,
                'name_ar' => 'Copy of ' . $blueprint->name_ar,
                'description_en' => $blueprint->description_en,
                'description_ar' => $blueprint->description_ar,
                'scope' => $blueprint->scope,
                'department_id' => $blueprint->department_id,
                'is_locked' => false,
                'is_active' => false,
                'created_by_user_id' => $user->id,
            ]);

            // Copy stages
            $stageMap = [];
            foreach ($blueprint->stages as $stage) {
                $newStage = BlueprintStage::create([
                    'blueprint_id' => $newBlueprint->id,
                    'stage_type_id' => $stage->stage_type_id,
                    'sla_policy_id' => $stage->sla_policy_id,
                    'name_en' => $stage->name_en,
                    'name_ar' => $stage->name_ar,
                    'description_en' => $stage->description_en,
                    'description_ar' => $stage->description_ar,
                    'sequence_order' => $stage->sequence_order,
                    'assignment_type' => $stage->assignment_type,
                    'assigned_position_id' => $stage->assigned_position_id,
                    'assigned_department_id' => $stage->assigned_department_id,
                    'assignment_cardinality' => $stage->assignment_cardinality,
                    'completion_rule' => $stage->completion_rule,
                    'escalation_position_id' => $stage->escalation_position_id,
                ]);
                $stageMap[$stage->id] = $newStage->id;

                // Copy sub-stages
                foreach ($stage->subStages as $subStage) {
                    BlueprintSubStage::create([
                        'blueprint_stage_id' => $newStage->id,
                        'sla_policy_id' => $subStage->sla_policy_id,
                        'name_en' => $subStage->name_en,
                        'name_ar' => $subStage->name_ar,
                        'description_en' => $subStage->description_en,
                        'description_ar' => $subStage->description_ar,
                        'sequence_order' => $subStage->sequence_order,
                        'is_required' => $subStage->is_required,
                        'assignment_type' => $subStage->assignment_type,
                        'assigned_position_id' => $subStage->assigned_position_id,
                        'assigned_department_id' => $subStage->assigned_department_id,
                        'assignment_cardinality' => $subStage->assignment_cardinality,
                        'completion_rule' => $subStage->completion_rule,
                    ]);
                }
            }

            // Copy transitions using stage map
            foreach ($blueprint->transitions as $transition) {
                BlueprintTransition::create([
                    'blueprint_id' => $newBlueprint->id,
                    'from_stage_id' => $stageMap[$transition->from_stage_id],
                    'to_stage_id' => $stageMap[$transition->to_stage_id],
                    'transition_type' => $transition->transition_type,
                    'return_reason_required' => $transition->return_reason_required,
                ]);
            }

            event(new BlueprintDuplicated($newBlueprint, $blueprint));

            return $newBlueprint->load('stages.subStages', 'transitions');
        });
    } catch (\Throwable $e) {
        Log::channel('blueprint')->error('Failed to duplicate blueprint', [
            'tenant_slug' => tenant()?->slug,
            'action' => 'blueprint.duplicate',
            'entity_type' => 'blueprint',
            'entity_id' => $blueprint->public_id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**Rules:** `coding-standards.md` — Database Transactions (duplicate, reorder, activate), Error Handling & Logging (try/catch + structured context), Events (ShouldDispatchAfterCommit).

---

### 5. Controllers

**One-line summary:** Thin controllers — validate → delegate to service → return API Resource. Use `HasRateLimiting` trait.

**Key decisions:**
- All controllers use constructor injection for services
- Rate limiting: `LIST` for index/show, `MUTATE` for create/update/delete
- Route model binding resolves by `public_id` automatically (TenantModel trait)
- Blueprint-scoped controllers (stages, sub-stages, transitions) receive `Blueprint $blueprint` and verify `!$blueprint->is_locked` before calling service

**Code snippet (pattern — BlueprintStageController):**
```php
<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Requests\StoreBlueprintStageRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintStageRequest;
use App\Modules\Blueprint\Requests\ReorderStagesRequest;
use App\Modules\Blueprint\Resources\BlueprintStageResource;
use App\Modules\Blueprint\Services\BlueprintStageService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintStageController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintStageService $stageService,
    ) {}

    public function index(Request $request, Blueprint $blueprint): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);
        return response()->json(BlueprintStageResource::collection($blueprint->stages()->with('subStages')->get()));
    }

    public function store(StoreBlueprintStageRequest $request, Blueprint $blueprint): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stage = $this->stageService->create($blueprint, $request->validated());
        return response()->json(new BlueprintStageResource($stage->load('subStages')), 201);
    }

    public function update(UpdateBlueprintStageRequest $request, Blueprint $blueprint, BlueprintStage $stage): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stage = $this->stageService->update($blueprint, $stage, $request->validated());
        return response()->json(new BlueprintStageResource($stage->load('subStages')));
    }

    public function destroy(Request $request, Blueprint $blueprint, BlueprintStage $stage): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->stageService->delete($blueprint, $stage);
        return response()->json(null, 204);
    }

    public function reorder(ReorderStagesRequest $request, Blueprint $blueprint): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->stageService->reorder($blueprint, $request->validated('stages'));
        return response()->json(BlueprintStageResource::collection($blueprint->stages()->with('subStages')->get()));
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin, no business logic), Rate Limiting (HasRateLimiting trait).

---

### 6. Form Requests

**One-line summary:** Validation rules in dedicated Form Request classes. Use `Rule::enum()` for enum fields. `authorize()` returns `true` (ABAC handled by middleware).

**Key decisions:**
- `StoreBlueprintRequest` validates `scope` via `Rule::enum(BlueprintScope::class)`
- `department_id` is required only when `scope = 2`
- `StoreBlueprintStageRequest` validates `assignment_type` via `Rule::enum(AssignmentType::class)`
- Conditional validation: `assigned_position_id` required when `assignment_type = 1`, etc.

**Code snippet (pattern — StoreBlueprintRequest):**
```php
<?php

namespace App\Modules\Blueprint\Requests;

use App\Modules\Blueprint\Enums\BlueprintScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlueprintRequest extends FormRequest
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
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'category_id' => ['required', 'uuid', 'exists:blueprint_categories,public_id'],
            'scope' => ['required', Rule::enum(BlueprintScope::class)],
            'department_id' => ['required_if:scope,' . BlueprintScope::Department->value, 'nullable', 'uuid', 'exists:departments,public_id'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation (Form Request classes, `$request->validated()` only).

---

### 7. API Resources

**One-line summary:** Transform internal models to JSON. Expose `public_id` only. Bilingual fallback: `name_en ?? name_ar`.

**Code snippet (pattern — BlueprintStageResource):**
```php
<?php

namespace App\Modules\Blueprint\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'blueprint_id' => $this->blueprint->public_id,
            'stage_type' => new StageTypeResource($this->whenLoaded('stageType')),
            'sla_policy' => new SlaPolicyResource($this->whenLoaded('slaPolicy')),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?? $this->description_ar,
            'sequence_order' => $this->sequence_order,
            'assignment_type' => $this->assignment_type,
            'assigned_position_id' => $this->assignedPosition?->public_id,
            'assigned_department_id' => $this->assignedDepartment?->public_id,
            'assignment_cardinality' => $this->assignment_cardinality,
            'completion_rule' => $this->completion_rule,
            'escalation_position_id' => $this->escalationPosition?->public_id,
            'sub_stages' => BlueprintSubStageResource::collection($this->whenLoaded('subStages')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources (public_id only, never internal id).

---

### 8. Events

**One-line summary:** All events implement `ShouldDispatchAfterCommit`. Use `Dispatchable` trait.

**Code snippet (pattern):**
```php
<?php

namespace App\Modules\Blueprint\Events;

use App\Modules\Blueprint\Models\Blueprint;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BlueprintCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Blueprint $blueprint) {}
}
```

**Rules:** `coding-standards.md` — Domain Events (ShouldDispatchAfterCommit is non-negotiable).

---

### 9. Exceptions

**One-line summary:** Domain exceptions extend `Exception`. Register renderable handlers in `bootstrap/app.php`.

**Code snippet (pattern):**
```php
<?php

namespace App\Modules\Blueprint\Exceptions;

use Exception;

class BlueprintLockedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Blueprint is locked and cannot be modified.');
    }
}
```

**Registration in `bootstrap/app.php`:**
```php
$exceptions->renderable(fn (BlueprintLockedException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (InvalidStageSequenceException $e) => response()->json(['message' => $e->getMessage()], 422));
// ... register all blueprint exceptions
```

**Rules:** `coding-standards.md` — Error Handling (register in bootstrap/app.php).

---

### 10. Routes

**One-line summary:** Route file under `routes/api/v1/blueprints.php`. Include in `routes/api.php`. Use `auth:sanctum` + `capability:` middleware.

**Code snippet (full routes file):**
```php
<?php

use App\Modules\Blueprint\Controllers\BlueprintCategoryController;
use App\Modules\Blueprint\Controllers\BlueprintController;
use App\Modules\Blueprint\Controllers\BlueprintStageController;
use App\Modules\Blueprint\Controllers\BlueprintSubStageController;
use App\Modules\Blueprint\Controllers\BlueprintTransitionController;
use App\Modules\Blueprint\Controllers\SlaPolicyController;
use App\Modules\Blueprint\Controllers\StageTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('blueprints')->group(function () {
        // Categories
        Route::get('categories', [BlueprintCategoryController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('categories', [BlueprintCategoryController::class, 'store']);
            Route::put('categories/{category}', [BlueprintCategoryController::class, 'update']);
            Route::post('categories/{category}/deactivate', [BlueprintCategoryController::class, 'deactivate']);
            Route::post('categories/{category}/reactivate', [BlueprintCategoryController::class, 'reactivate']);
        });

        // Stage Types
        Route::get('stage-types', [StageTypeController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('stage-types', [StageTypeController::class, 'store']);
            Route::put('stage-types/{stageType}', [StageTypeController::class, 'update']);
            Route::delete('stage-types/{stageType}', [StageTypeController::class, 'destroy']);
        });

        // SLA Policies
        Route::get('sla-policies', [SlaPolicyController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('sla-policies', [SlaPolicyController::class, 'store']);
            Route::put('sla-policies/{slaPolicy}', [SlaPolicyController::class, 'update']);
            Route::delete('sla-policies/{slaPolicy}', [SlaPolicyController::class, 'destroy']);
        });

        // Blueprints
        Route::get('/', [BlueprintController::class, 'index']);
        Route::get('{blueprint}', [BlueprintController::class, 'show']);
        Route::middleware(['capability:blueprint.create.organization,blueprint.create.department'])->group(function () {
            Route::post('/', [BlueprintController::class, 'store']);
        });
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::put('{blueprint}', [BlueprintController::class, 'update']);
            Route::post('{blueprint}/activate', [BlueprintController::class, 'activate']);
            Route::post('{blueprint}/deactivate', [BlueprintController::class, 'deactivate']);
            Route::post('{blueprint}/duplicate', [BlueprintController::class, 'duplicate']);
        });

        // Stages
        Route::get('{blueprint}/stages', [BlueprintStageController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('{blueprint}/stages', [BlueprintStageController::class, 'store']);
            Route::put('{blueprint}/stages/{stage}', [BlueprintStageController::class, 'update']);
            Route::delete('{blueprint}/stages/{stage}', [BlueprintStageController::class, 'destroy']);
            Route::post('{blueprint}/stages/reorder', [BlueprintStageController::class, 'reorder']);
        });

        // Sub-stages
        Route::get('{blueprint}/stages/{stage}/sub-stages', [BlueprintSubStageController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('{blueprint}/stages/{stage}/sub-stages', [BlueprintSubStageController::class, 'store']);
            Route::put('{blueprint}/stages/{stage}/sub-stages/{subStage}', [BlueprintSubStageController::class, 'update']);
            Route::delete('{blueprint}/stages/{stage}/sub-stages/{subStage}', [BlueprintSubStageController::class, 'destroy']);
            Route::post('{blueprint}/stages/{stage}/sub-stages/reorder', [BlueprintSubStageController::class, 'reorder']);
        });

        // Transitions
        Route::get('{blueprint}/transitions', [BlueprintTransitionController::class, 'index']);
        Route::middleware(['capability:blueprint.manage'])->group(function () {
            Route::post('{blueprint}/transitions', [BlueprintTransitionController::class, 'store']);
            Route::put('{blueprint}/transitions/{transition}', [BlueprintTransitionController::class, 'update']);
            Route::delete('{blueprint}/transitions/{transition}', [BlueprintTransitionController::class, 'destroy']);
        });
    });
});
```

**Rules:** `coding-standards.md` — Rate Limiting (applied in controllers, not routes).

---

### 11. Caching

**One-line summary:** Cache reference data (categories, stage types, SLA policies) at warm tier (300s). Cache individual blueprint structures at warm tier (300s). Invalidate on domain events.

**Key decisions:**
- Cache keys are tenant-prefixed: `{tenant_slug}:blueprint:...`
- Use `Cache::tags()` for grouped invalidation if Redis driver supports tags
- Paginated blueprint lists are NOT cached

**Code snippet (pattern — CategoryService):**
```php
public function getAll(): Collection
{
    $tenantSlug = tenant()?->slug ?? 'central';
    return Cache::remember("{$tenantSlug}:blueprint:categories:all", 300, function () {
        return BlueprintCategory::active()->orderBy('display_order')->get();
    });
}

public function create(array $data): BlueprintCategory
{
    $category = BlueprintCategory::create([...]);
    $tenantSlug = tenant()?->slug ?? 'central';
    Cache::forget("{$tenantSlug}:blueprint:categories:all");
    event(new BlueprintCategoryCreated($category));
    return $category;
}
```

**Rules:** `coding-standards.md` — Caching (tenant-prefixed keys, invalidation by events, warm tier 300s).

---

### 12. Seeding

**One-line summary:** Seed 5 default stage types per tenant on provisioning.

**Code snippet (update `TenantDatabaseSeeder`):**
```php
StageType::insert([
    ['public_id' => Str::uuid7(), 'name_en' => 'Action', 'name_ar' => 'إجراء', 'is_system_default' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => Str::uuid7(), 'name_en' => 'Review', 'name_ar' => 'مراجعة', 'is_system_default' => true, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => Str::uuid7(), 'name_en' => 'Approval', 'name_ar' => 'موافقة', 'is_system_default' => true, 'display_order' => 3, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => Str::uuid7(), 'name_en' => 'Decision', 'name_ar' => 'قرار', 'is_system_default' => true, 'display_order' => 4, 'created_at' => now(), 'updated_at' => now()],
    ['public_id' => Str::uuid7(), 'name_en' => 'Information Gathering', 'name_ar' => 'جمع المعلومات', 'is_system_default' => true, 'display_order' => 5, 'created_at' => now(), 'updated_at' => now()],
]);
```

---

### 13. Tests

**One-line summary:** Feature tests for all endpoints. Use factories. Test happy path + auth failure + validation failure + lock behavior.

**Test cases (example — BlueprintTest):**
```php
// Test 1: Create blueprint with organization scope
$post('api/v1/blueprints', [
    'name_ar' => 'Test Blueprint',
    'category_id' => $category->public_id,
    'scope' => 1,
])
->assertCreated()
->assertJsonPath('data.name_ar', 'Test Blueprint');

// Test 2: Create blueprint with department scope without department_id fails
$post('api/v1/blueprints', [
    'name_ar' => 'Test Blueprint',
    'category_id' => $category->public_id,
    'scope' => 2,
])
->assertUnprocessable()
->assertJsonValidationErrors('department_id');

// Test 3: Locked blueprint rejects mutation
$blueprint->update(['is_locked' => true]);
$put("api/v1/blueprints/{$blueprint->public_id}", ['name_ar' => 'New Name'])
->assertUnprocessable()
->assertJsonPath('message', 'Blueprint is locked and cannot be modified.');
```

---

## Execution Order

1. **Enums** — Create all 6 enum classes (no dependencies)
2. **Migrations** — Create all 7 migration files (run `php artisan migrate` on tenant template)
3. **Models** — Create all 7 model classes with relationships and casts
4. **Exceptions** — Create all 8 exception classes; register in `bootstrap/app.php`
5. **Events** — Create all 20+ event classes
6. **Logging** — Add `blueprint` channel to `config/logging.php`
7. **Services** — Create all 6 service classes
8. **Requests** — Create all Form Request classes
9. **Resources** — Create all API Resource classes
10. **Controllers** — Create all 7 controller classes
11. **Routes** — Create `routes/api/v1/blueprints.php`; include in `routes/api.php`
12. **Seeding** — Update `TenantDatabaseSeeder` with default stage types
13. **Tests** — Create all feature test files
14. **Run tests** — `php artisan test --compact`
15. **Run Pint** — `vendor/bin/pint --dirty`

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/blueprints/categories` | Sanctum | — | List active categories |
| POST | `/api/v1/blueprints/categories` | Sanctum | `blueprint.manage` | Create category |
| PUT | `/api/v1/blueprints/categories/{category}` | Sanctum | `blueprint.manage` | Update category |
| POST | `/api/v1/blueprints/categories/{category}/deactivate` | Sanctum | `blueprint.manage` | Deactivate category |
| POST | `/api/v1/blueprints/categories/{category}/reactivate` | Sanctum | `blueprint.manage` | Reactivate category |
| GET | `/api/v1/blueprints/stage-types` | Sanctum | — | List active stage types |
| POST | `/api/v1/blueprints/stage-types` | Sanctum | `blueprint.manage` | Create stage type |
| PUT | `/api/v1/blueprints/stage-types/{stageType}` | Sanctum | `blueprint.manage` | Update stage type |
| DELETE | `/api/v1/blueprints/stage-types/{stageType}` | Sanctum | `blueprint.manage` | Delete stage type |
| GET | `/api/v1/blueprints/sla-policies` | Sanctum | `blueprint.view_library` | List SLA policies |
| POST | `/api/v1/blueprints/sla-policies` | Sanctum | `blueprint.manage` | Create SLA policy |
| PUT | `/api/v1/blueprints/sla-policies/{slaPolicy}` | Sanctum | `blueprint.manage` | Update SLA policy |
| DELETE | `/api/v1/blueprints/sla-policies/{slaPolicy}` | Sanctum | `blueprint.manage` | Delete SLA policy |
| GET | `/api/v1/blueprints` | Sanctum | `blueprint.view_library` | List blueprints (cursor paginated) |
| POST | `/api/v1/blueprints` | Sanctum | `blueprint.create.*` | Create blueprint |
| GET | `/api/v1/blueprints/{blueprint}` | Sanctum | `blueprint.view_library` | Show blueprint |
| PUT | `/api/v1/blueprints/{blueprint}` | Sanctum | `blueprint.manage` | Update blueprint |
| POST | `/api/v1/blueprints/{blueprint}/activate` | Sanctum | `blueprint.manage` | Activate blueprint |
| POST | `/api/v1/blueprints/{blueprint}/deactivate` | Sanctum | `blueprint.manage` | Deactivate blueprint |
| POST | `/api/v1/blueprints/{blueprint}/duplicate` | Sanctum | `blueprint.manage` | Duplicate blueprint |
| GET | `/api/v1/blueprints/{blueprint}/stages` | Sanctum | `blueprint.view_library` | List stages |
| POST | `/api/v1/blueprints/{blueprint}/stages` | Sanctum | `blueprint.manage` | Add stage |
| PUT | `/api/v1/blueprints/{blueprint}/stages/{stage}` | Sanctum | `blueprint.manage` | Update stage |
| DELETE | `/api/v1/blueprints/{blueprint}/stages/{stage}` | Sanctum | `blueprint.manage` | Delete stage |
| POST | `/api/v1/blueprints/{blueprint}/stages/reorder` | Sanctum | `blueprint.manage` | Reorder stages |
| GET | `/api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages` | Sanctum | `blueprint.view_library` | List sub-stages |
| POST | `/api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages` | Sanctum | `blueprint.manage` | Add sub-stage |
| PUT | `/api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/{subStage}` | Sanctum | `blueprint.manage` | Update sub-stage |
| DELETE | `/api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/{subStage}` | Sanctum | `blueprint.manage` | Delete sub-stage |
| POST | `/api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/reorder` | Sanctum | `blueprint.manage` | Reorder sub-stages |
| GET | `/api/v1/blueprints/{blueprint}/transitions` | Sanctum | `blueprint.view_library` | List transitions |
| POST | `/api/v1/blueprints/{blueprint}/transitions` | Sanctum | `blueprint.manage` | Create transition |
| PUT | `/api/v1/blueprints/{blueprint}/transitions/{transition}` | Sanctum | `blueprint.manage` | Update transition |
| DELETE | `/api/v1/blueprints/{blueprint}/transitions/{transition}` | Sanctum | `blueprint.manage` | Delete transition |

---

## What to Test Manually

1. **Happy path:** Create category → create stage type → create SLA policy → create blueprint → add stages → add sub-stages → add transitions → activate blueprint → duplicate blueprint.
2. **Blueprint lock:** Launch a task from blueprint (via Spec 005 when ready) → verify blueprint is locked → verify all mutations return 422.
3. **Department scope:** Create blueprint with `scope=department` → verify `department_id` is required → verify list filters by department scope.
4. **Cascade delete:** Delete stage → verify sub-stages and transitions are deleted.
5. **Reorder:** Reorder stages via bulk endpoint → verify `sequence_order` updated atomically.
6. **Duplicate:** Duplicate blueprint → verify new blueprint has new `public_id`, `is_locked=false`, `is_active=false`, and same structure.
7. **In-use protection:** Try to delete SLA policy referenced by stage → verify 422. Try to delete category referenced by blueprint → verify 422.
8. **Rate limiting:** Hit mutate endpoint 31 times in 1 minute → verify 429.
9. **Caching:** List categories → create new category → verify list returns new category without stale cache.
10. **Auth failure:** Call mutate endpoint without `blueprint.manage` → verify 403.
