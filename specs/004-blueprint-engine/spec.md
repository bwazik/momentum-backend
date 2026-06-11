# Spec: Blueprint Engine

> **Number:** 004
> **Date:** 2026-06-10
> **Status:** `draft`
> **Milestone:** M3 — Blueprint Engine
> **Depends on:** `001-platform-tenancy` (tenant DB, base models, `public_id`), `002-organization-structure` (departments, positions, authority grades, working calendars), `003-iam-abac` (users, capabilities, ABAC policy engine, position assignments)
> **Provides APIs:** Blueprint Category CRUD, Stage Type CRUD, SLA Policy CRUD, Blueprint CRUD with stages/sub-stages/transitions, Blueprint lock, Blueprint duplicate, Blueprint activation/deactivation
> **Contract status:** `draft`
> **Frontend spec:** `../frontend/specs/004-blueprint-builder`
> **Author:** Momentum init
> **Branch:** `feat/004-blueprint-engine`
> **Base branch:** `main`

---

## Problem

Every task in Gov TMS follows a structured lifecycle. Without a **Blueprint Engine**, the platform cannot define reusable workflow templates, enforce stage-level accountability, calculate SLA deadlines, or route assignments dynamically. Today, government ministries manage task lifecycles through informal rules, memoranda, and manual tracking — leading to inconsistent execution, missed deadlines, and unclear accountability.

The platform needs a **Blueprint** as a first-class concept: a reusable template that defines the stages a task passes through, the sub-stages within each stage, the SLA policies governing deadlines, the assignment rules determining who is responsible at each step, and the transition rules governing how a task moves forward (or backward). Once a task is launched from a blueprint, the blueprint's rules become immutable — preventing mid-flight changes that would break accountability or SLA calculations.

Without this spec:
- **Task creation (Spec 005)** cannot launch tasks with defined stages.
- **Stage lifecycle (Spec 006)** cannot progress tasks through a known workflow.
- **SLA & Escalation (Spec 007)** cannot calculate deadlines because there are no SLA policies attached to stages.
- **Assignment resolution (Spec 005)** cannot determine who should receive a task because there are no assignment rules.

---

## Goal

Deliver the Blueprint module — blueprints, blueprint categories, stage types, stages, sub-stages, SLA policies, and transitions — as the **workflow definition foundation** that Task, Tracking, and SLA modules depend on. After this spec, a tenant admin can design, validate, activate, and lock blueprints; task creators can launch tasks from them; and downstream modules can consume stage definitions, SLA policies, and assignment rules at runtime.

All data lives in the tenant DB. All endpoints use `public_id`. All mutating endpoints enforce ABAC via `RequireCapability`. Blueprints are **locked** after the first task is launched, making them immutable for accountability.

---

## User Stories

### Blueprint Categories

- As a **tenant admin**, I want to create blueprint categories (e.g., "Financial", "HR", "Projects"), so that blueprints are organized and searchable.
- As a **tenant admin**, I want to update a category's bilingual name and display order, so that labels stay current and appear in the right order.
- As a **tenant admin**, I want to deactivate a category, so that it cannot be used for new blueprints but existing blueprints remain functional.
- As an **authorized user**, I want to list blueprint categories, so that I can filter blueprints when creating a task.

### Stage Types

- As a **tenant admin**, I want to view the default stage types (Action, Review, Approval, Decision, Information Gathering), so that I can classify stages consistently.
- As a **tenant admin**, I want to create custom stage types, so that my organization's terminology is reflected.
- As a **tenant admin**, I want to update or deactivate custom stage types, so that I can manage the catalog without affecting system defaults.

### Blueprints

- As a **tenant admin**, I want to create a blueprint with a name, description, category, and scope, so that I can define a reusable workflow template.
- As a **tenant admin**, I want to save a blueprint as a draft, so that I can build it incrementally before activation.
- As a **tenant admin**, I want to activate a blueprint, so that it becomes available for task creation.
- As a **tenant admin**, I want to deactivate a blueprint, so that no new tasks can be launched from it while existing tasks continue.
- As a **tenant admin**, I want to duplicate an existing blueprint, so that I can create a variant without rebuilding from scratch.
- As a **tenant admin**, I want to update a blueprint's metadata while it is still in draft, so that I can refine it before activation.
- As an **authorized user**, I want to list active blueprints, so that I can select one when creating a task.
- As an **authorized user**, I want to view a blueprint's full structure (stages, sub-stages, SLA, transitions), so that I understand the workflow before launching a task.

### Stages

- As a **tenant admin**, I want to add stages to a blueprint in a specific order, so that the workflow progresses logically (e.g., "Review → Approval → Execution → Closure").
- As a **tenant admin**, I want to set a stage's type (Action, Review, Approval, etc.), so that the system understands the nature of the work.
- As a **tenant admin**, I want to set a stage's assignment rule (specific position, department head, or manual at launch), so that the system knows who is responsible when a task enters this stage.
- As a **tenant admin**, I want to set a stage's SLA policy, so that deadlines are automatically calculated when a task enters this stage.
- As a **tenant admin**, I want to set a stage's escalation target, so that SLA breaches are routed to the correct authority.
- As a **tenant admin**, I want to set assignment cardinality and completion rule, so that the system knows whether one or multiple assignees are required, and how completion is determined.
- As a **tenant admin**, I want to reorder stages within a blueprint, so that I can adjust the workflow before activation.
- As a **tenant admin**, I want to remove a stage from a draft blueprint, so that I can correct mistakes.
- As an **authorized user**, I want to view a blueprint's stages in order, so that I understand the task lifecycle.

### Sub-stages

- As a **tenant admin**, I want to add sub-stages within a stage, so that internal steps are visible (e.g., "Data Entry → Verification → Manager Sign-off" within the "Review" stage).
- As a **tenant admin**, I want to set a sub-stage as required or optional, so that the parent stage advances only when required sub-stages are completed.
- As a **tenant admin**, I want to set a sub-stage's assignment rule, SLA, cardinality, and completion rule, so that internal steps have their own accountability.
- As a **tenant admin**, I want to reorder sub-stages within a stage, so that I can adjust the internal flow.
- As an **authorized user**, I want to see sub-stages when viewing a task's current stage, so that I know what internal steps remain.

### SLA Policies

- As a **tenant admin**, I want to create SLA policies with a name, duration value, unit (hours or days), and warning threshold, so that deadlines are calculated consistently.
- As a **tenant admin**, I want to update an SLA policy, so that I can adjust deadlines for future tasks.
- As a **tenant admin**, I want to delete an SLA policy that is not used by any blueprint, so that I can clean up unused definitions.
- As an **authorized user**, I want to see the SLA deadline and warning threshold for a task stage, so that I know how much time remains.

### Transitions

- As a **tenant admin**, I want to define advance transitions between stages, so that the workflow knows the forward paths.
- As a **tenant admin**, I want to define return transitions to earlier stages, so that tasks can be sent back with a mandatory reason.
- As an **authorized user**, I want to see the available next stages from the current stage, so that I know what actions are possible.

### Blueprint Lock

- As the **system**, I want to lock a blueprint once the first task is launched, so that stage definitions, SLA policies, and assignment rules cannot be modified — preserving accountability for all tasks created from it.
- As a **tenant admin**, I want to see whether a blueprint is locked, so that I know I cannot edit it.

---

## Acceptance Criteria

### Blueprint Categories

- [ ] `blueprint_categories` table in tenant DB with columns: `id`, `public_id`, `name_en`, `name_ar`, `display_order` (smallint, default 0), `is_active`, `created_at`, `updated_at`, `deleted_at`
- [ ] `name_ar` required; `name_en` optional (system copies `name_ar` if empty)
- [ ] `GET /api/v1/blueprints/categories` — list all active categories ordered by `display_order` (full list, expected < 100). Requires authentication.
- [ ] `POST /api/v1/blueprints/categories` — create category. Requires `blueprint.manage` capability.
- [ ] `PUT /api/v1/blueprints/categories/{category}` — update name, order, or active status. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/categories/{category}/deactivate` — set `is_active = false`. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/categories/{category}/reactivate` — set `is_active = true`. Requires `blueprint.manage`.
- [ ] Deleting a category is not allowed if it is referenced by active blueprints.

### Stage Types

- [ ] `stage_types` table in tenant DB with columns: `id`, `public_id`, `name_en`, `name_ar`, `is_system_default`, `is_active`, `display_order`, `created_at`, `updated_at`, `deleted_at`
- [ ] System ships with 5 defaults: Action, Review, Approval, Decision, Information Gathering (`is_system_default = true`, `is_active = true`)
- [ ] `GET /api/v1/blueprints/stage-types` — list all active stage types ordered by `display_order` (full list). Requires authentication.
- [ ] `POST /api/v1/blueprints/stage-types` — create custom stage type. Requires `blueprint.manage`.
- [ ] `PUT /api/v1/blueprints/stage-types/{stage_type}` — update custom stage type name or order. System defaults (`is_system_default = true`) cannot be renamed or deactivated. Requires `blueprint.manage`.
- [ ] `DELETE /api/v1/blueprints/stage-types/{stage_type}` — delete custom stage type. Rejected if referenced by any blueprint stage. Requires `blueprint.manage`.

### SLA Policies

- [ ] `sla_policies` table in tenant DB with columns: `id`, `public_id`, `name_en`, `name_ar`, `sla_value` (smallint), `sla_unit` (TINYINT: 1=hours, 2=days), `warning_threshold_percentage` (smallint, default 75), `is_active`, `created_at`, `updated_at`, `deleted_at`
- [ ] `name_ar` required; `name_en` optional (system copies `name_ar` if empty)
- [ ] `GET /api/v1/blueprints/sla-policies` — list active SLA policies (full list, expected < 100). Requires `blueprint.view` or `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/sla-policies` — create SLA policy. Requires `blueprint.manage`.
- [ ] `PUT /api/v1/blueprints/sla-policies/{sla_policy}` — update SLA policy. Requires `blueprint.manage`.
- [ ] `DELETE /api/v1/blueprints/sla-policies/{sla_policy}` — delete SLA policy. Rejected if referenced by any blueprint stage. Requires `blueprint.manage`.

### Blueprints

- [ ] `blueprints` table in tenant DB with columns: `id`, `public_id`, `category_id` (FK blueprint_categories), `name_en`, `name_ar`, `description_en`, `description_ar`, `scope` (TINYINT: 1=organization, 2=department), `department_id` (nullable FK departments, required when `scope = 2`), `is_locked` (boolean, default false), `is_active` (boolean, default true), `created_by_user_id` (FK users), `created_at`, `updated_at`, `deleted_at`
- [ ] `name_ar` required; `name_en` optional (system copies `name_ar` if empty)
- [ ] `description_ar` optional; `description_en` optional (system copies `description_ar` if empty)
- [ ] `is_locked` is set to `true` automatically when the first task is launched from this blueprint.
- [ ] `GET /api/v1/blueprints` — list blueprints with cursor pagination. Filters: `is_active`, `category_id`, `is_locked`, `scope`, `department_id`, `search` (name). Requires `blueprint.view_library` or `blueprint.manage`.
- [ ] `GET /api/v1/blueprints/{blueprint}` — show blueprint with full structure (stages, sub-stages, SLA policies, transitions). Requires `blueprint.view_library` or `blueprint.manage`.
- [ ] `POST /api/v1/blueprints` — create blueprint. `name_ar` required, `category_id` required, `scope` required. `department_id` required when `scope = 2`. Requires `blueprint.create.organization` (when `scope = 1`) or `blueprint.create.department` (when `scope = 2`).
- [ ] `PUT /api/v1/blueprints/{blueprint}` — update blueprint metadata. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/activate` — set `is_active = true`. Only allowed if `is_locked = false` and at least one stage exists. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/deactivate` — set `is_active = false`. Allowed even if locked. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/duplicate` — creates a new blueprint with the same stages, sub-stages, SLA refs, and transitions, but with `is_active = false`, `is_locked = false`, and a new `public_id`. The new blueprint's name is prefixed with "Copy of ". Requires `blueprint.manage`.
- [ ] Locked blueprints reject all mutating operations (update, activate, add stage, etc.) with a 422 error: "Blueprint is locked and cannot be modified."
- [ ] Deleting a blueprint is soft-delete only. If tasks exist, the blueprint is kept but marked inactive; hard delete is rejected.

### Stages

- [ ] `blueprint_stages` table in tenant DB with columns: `id`, `public_id`, `blueprint_id` (FK blueprints), `stage_type_id` (FK stage_types), `sla_policy_id` (nullable FK sla_policies), `name_en`, `name_ar`, `description_en`, `description_ar`, `sequence_order` (smallint), `assignment_type` (TINYINT: 1=specific_position, 2=department_head, 3=manual_at_launch), `assigned_position_id` (nullable FK positions), `assigned_department_id` (nullable FK departments), `assignment_cardinality` (TINYINT: 1=single, 2=multiple, default 1), `completion_rule` (TINYINT: 1=any_assignee, 2=all_assignees, 3=lead_assignee, default 1), `escalation_position_id` (nullable FK positions), `created_at`, `updated_at`
- [ ] `sequence_order` must be unique per blueprint.
- [ ] `assigned_position_id` required when `assignment_type = 1`; `assigned_department_id` required when `assignment_type = 2`; both null when `assignment_type = 3`.
- [ ] `escalation_position_id` overrides the default escalation chain (reports_to). If null, escalation follows the assigned position's reporting line.
- [ ] `GET /api/v1/blueprints/{blueprint}/stages` — list stages in sequence order. Full list (expected < 50 per blueprint). Requires `blueprint.view_library` or `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/stages` — add stage. Only allowed if `is_locked = false`. `sequence_order` is auto-assigned (last + 1) unless explicitly provided. Requires `blueprint.manage`.
- [ ] `PUT /api/v1/blueprints/{blueprint}/stages/{stage}` — update stage. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `DELETE /api/v1/blueprints/{blueprint}/stages/{stage}` — remove stage. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/stages/reorder` — bulk reorder. Accepts array of `{public_id, sequence_order}`. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] Stage assignment rules reference Organization module entities via `public_id` in API but internal FK in DB. Validation ensures referenced entities exist and are active.

### Sub-stages

- [ ] `blueprint_sub_stages` table in tenant DB with columns: `id`, `public_id`, `blueprint_stage_id` (FK blueprint_stages), `sla_policy_id` (nullable FK sla_policies), `name_en`, `name_ar`, `description_en`, `description_ar`, `sequence_order` (smallint), `is_required` (boolean, default true), `assignment_type` (TINYINT: 1=specific_position, 2=department_head, 3=manual_at_launch), `assigned_position_id` (nullable FK positions), `assigned_department_id` (nullable FK departments), `assignment_cardinality` (TINYINT: 1=single, 2=multiple, default 1), `completion_rule` (TINYINT: 1=any_assignee, 2=all_assignees, 3=lead_assignee, default 1), `created_at`, `updated_at`
- [ ] `sequence_order` must be unique per stage.
- [ ] `GET /api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages` — list sub-stages in order. Full list (expected < 20 per stage). Requires `blueprint.view_library` or `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages` — add sub-stage. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `PUT /api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/{sub_stage}` — update sub-stage. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `DELETE /api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/{sub_stage}` — remove sub-stage. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/stages/{stage}/sub-stages/reorder` — bulk reorder. Only allowed if `is_locked = false`. Requires `blueprint.manage`.

### Transitions

- [ ] `blueprint_transitions` table in tenant DB with columns: `id`, `blueprint_id` (FK blueprints), `from_stage_id` (FK blueprint_stages), `to_stage_id` (FK blueprint_stages), `transition_type` (TINYINT: 1=advance, 2=return), `return_reason_required` (boolean, default false), `created_at`
- [ ] `from_stage_id` and `to_stage_id` must belong to the same blueprint.
- [ ] `transition_type = 1` (advance): `to_stage_id` must have a higher `sequence_order` than `from_stage_id`.
- [ ] `transition_type = 2` (return): `to_stage_id` must have a lower `sequence_order` than `from_stage_id`; `return_reason_required` defaults to `true`.
- [ ] `GET /api/v1/blueprints/{blueprint}/transitions` — list transitions. Full list (expected < 100 per blueprint). Requires `blueprint.view_library` or `blueprint.manage`.
- [ ] `POST /api/v1/blueprints/{blueprint}/transitions` — create transition. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `PUT /api/v1/blueprints/{blueprint}/transitions/{transition}` — update transition. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] `DELETE /api/v1/blueprints/{blueprint}/transitions/{transition}` — delete transition. Only allowed if `is_locked = false`. Requires `blueprint.manage`.
- [ ] A stage cannot transition to itself.

### General

- [ ] All endpoints follow `/api/v1/blueprints/` prefix.
- [ ] All responses use API Resources with `public_id` only — never expose internal `id`.
- [ ] All mutating endpoints require `blueprint.manage` capability via `RequireCapability` middleware.
- [ ] Blueprint creation requires `blueprint.create.organization` (for org-wide) or `blueprint.create.department` (for department-scoped) via `RequireCapability`.
- [ ] All list endpoints use `blueprint.view_library` or `blueprint.manage` capability.
- [ ] Bilingual fields: `name_ar` / `title_ar` / `description_ar` required; `name_en` / `title_en` / `description_en` optional, system copies Arabic if empty.
- [ ] Domain events emitted for all mutating actions: `BlueprintCreated`, `BlueprintActivated`, `BlueprintDeactivated`, `BlueprintLocked`, `BlueprintDuplicated`, `StageCreated`, `StageUpdated`, `StageDeleted`, `StageReordered`, `SubStageCreated`, `SubStageUpdated`, `SubStageDeleted`, `SubStageReordered`, `SlaPolicyCreated`, `SlaPolicyUpdated`, `SlaPolicyDeleted`, `TransitionCreated`, `TransitionUpdated`, `TransitionDeleted`, `BlueprintCategoryCreated`, `BlueprintCategoryUpdated`, `StageTypeCreated`, `StageTypeUpdated`.
- [ ] Feature tests cover: blueprint CRUD, activation, lock behavior, duplicate, stage CRUD with reorder, sub-stage CRUD, SLA policy CRUD, transition CRUD, category CRUD, stage type CRUD, assignment rule validation, department-scoped blueprint creation, blueprint lock rejection on mutation.

---

## Non-Functional Requirements

### Pagination

- Endpoints listing `blueprints` use **cursor pagination** (expected > 1000 rows per tenant). See `coding-standards.md` — Pagination Strategy.
- Endpoints listing `blueprint_categories`, `stage_types`, `sla_policies`, `stages`, `sub-stages`, and `transitions` return **full list** (expected < 100 rows per parent). See `coding-standards.md` — Exception: Small Stable Tables.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- Blueprint category catalog is cached at `{tenant_slug}:blueprint:categories:all` with TTL 300s (warm tier). Invalidated on any category create/update/deactivate event.
- Stage type catalog is cached at `{tenant_slug}:blueprint:stage_types:all` with TTL 300s (warm tier). Invalidated on any stage type create/update event.
- SLA policy catalog is cached at `{tenant_slug}:blueprint:sla_policies:all` with TTL 300s (warm tier). Invalidated on any SLA policy create/update/delete event.
- Active blueprint list is cached at `{tenant_slug}:blueprint:active:all` with TTL 300s (warm tier). Invalidated on blueprint activate/deactivate/lock/duplicate event.
- Individual blueprint structure is cached at `{tenant_slug}:blueprint:{blueprint_public_id}:structure` with TTL 300s (warm tier). Invalidated on any stage/sub-stage/SLA/transition mutation or blueprint lock.
- All cache keys are tenant-prefixed per `coding-standards.md` — Caching (Redis / phpredis).
- Paginated list results are **not** cached.

### Rate Limiting

- Auth endpoints: `RateLimits::AUTH_LOGIN` (5/min per email+IP)
- Mutating endpoints (blueprint create, update, activate, stage add, etc.): `RateLimits::MUTATE` (30/min per user)
- List endpoints: `RateLimits::LIST` (60/min per user)
- See `coding-standards.md` — Rate Limiting for `HasRateLimiting` trait usage.

### Database Transactions

- Blueprint duplication: `DB::transaction()` required — creates new blueprint, stages, sub-stages, SLA refs, and transitions in one atomic operation.
- Stage reorder: `DB::transaction()` required — updates multiple `sequence_order` values atomically.
- Sub-stage reorder: `DB::transaction()` required — updates multiple `sequence_order` values atomically.
- Blueprint activate: `DB::transaction()` required — updates `is_active` and validates that at least one stage exists.
- All other single-write operations (create category, create stage type, create SLA policy, add single stage) do not need a transaction.
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `blueprint`
- All service methods use try/catch with `Log::channel('blueprint')`
- Structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`
- Domain exceptions registered in `bootstrap/app.php`:
  - `BlueprintLockedException` — blueprint is locked, mutation rejected
  - `InvalidStageSequenceException` — stage order violation
  - `InvalidTransitionException` — transition rules violated (self-loop, wrong blueprint, sequence order mismatch)
  - `SlaPolicyInUseException` — cannot delete SLA policy referenced by stages
  - `StageTypeInUseException` — cannot delete stage type referenced by stages
  - `BlueprintCategoryInUseException` — cannot delete category referenced by blueprints
  - `InvalidBlueprintScopeException` — department_id missing when scope=department
  - `UnauthorizedBlueprintScopeException` — user lacks capability for requested scope
- See `coding-standards.md` — Error Handling & Logging.

### Enums

- `BlueprintScope` enum in `app/Modules/Blueprint/Enums/BlueprintScope.php`:
  - `Organization = 1`, `Department = 2`
- `AssignmentType` enum in `app/Modules/Blueprint/Enums/AssignmentType.php`:
  - `SpecificPosition = 1`, `DepartmentHead = 2`, `ManualAtLaunch = 3`
- `AssignmentCardinality` enum in `app/Modules/Blueprint/Enums/AssignmentCardinality.php`:
  - `Single = 1`, `Multiple = 2`
- `CompletionRule` enum in `app/Modules/Blueprint/Enums/CompletionRule.php`:
  - `AnyAssignee = 1`, `AllAssignees = 2`, `LeadAssignee = 3`
- `SlaUnit` enum in `app/Modules/Blueprint/Enums/SlaUnit.php`:
  - `Hours = 1`, `Days = 2`
- `TransitionType` enum in `app/Modules/Blueprint/Enums/TransitionType.php`:
  - `Advance = 1`, `Return = 2`
- All enums used in form requests via `Rule::enum(ClassName::class)` and in service logic — never raw integers.
- See `coding-standards.md` — Enum Usage.

### Queue Jobs

- No queue jobs required for this spec — all operations are synchronous CRUD.
- All domain events implement `ShouldDispatchAfterCommit` (consumed by Audit, Tracking, Notification modules in later specs).
- See `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **Task creation and launch** — Spec 005 (Task Execution) consumes blueprints but does not define them here.
- **Stage instance progression** — Spec 006 (Stage Lifecycle) handles runtime stage movement, not blueprint definitions.
- **SLA timer calculation** — Spec 007 (SLA & Escalation) consumes `sla_policies` and `working_calendars` to calculate real-time deadlines; this spec only defines the policy template.
- **Assignment resolution at runtime** — Spec 005 calls IAM + Organization to resolve who actually receives a task based on `assignment_type` and the referenced position/department.
- **Blueprint versioning** — `version` column is a placeholder for future versioning (V2). For MVP, duplication is the versioning mechanism.
- **Blueprint import/export** — deferred to V2.
- **Conditional stage logic** (e.g., "skip stage X if field Y is Z") — deferred to V2.
- **Parallel stages** — deferred to V2.
- **Stage forms** — deferred to V2.
- **Optional stages** — deferred to V2.
- **Branching conditions** — deferred to V2.
- **Least Workload / Round Robin resolution methods** — deferred to V2.
- **Full audit trail persistence** — Spec 015 will consume domain events. This spec emits events only.
- **Blueprint usage analytics** — Spec 009 (Analytics) will provide read-only reports.
- **Frontend blueprint builder UI** — handled by `../frontend/specs/004-blueprint-builder`.
- **Comments on blueprints** — deferred to V2.
- **Blueprint approval workflow** — tenant admin creates and activates directly; no multi-step approval for blueprint definition.

---

## Open Questions

- [ ] Should blueprint duplication copy the `category_id` or leave it unset? (Recommended: copy it, since the category is a grouping mechanism.)
- [ ] Should blueprint lock be a database column (`is_locked`) or a computed property (`exists tasks`)? (Recommended: database column for performance and to support explicit lock without tasks for governance.)
- [ ] Should `sla_policies` live in the Blueprint module or a shared SLA module? (Recommended: Blueprint module for MVP — they are template definitions, not runtime timers. Spec 007 may create a separate runtime SLA table if needed.)
- [ ] Should `blueprint_transitions` allow multiple transitions from the same `from_stage` to different `to_stage`s (branching)? (Recommended: yes, branching is allowed — task module will choose based on conditions.)
- [ ] Should deleting a blueprint stage cascade-delete its sub-stages and transitions? (Recommended: yes, cascade delete sub-stages and transitions referencing the stage.)
- [ ] Should `sequence_order` be an integer or a float (to allow insertion between stages without reordering)? (Recommended: integer for MVP; reorder endpoint handles insertions.)
- [ ] Should blueprint categories support nesting/hierarchy? (Recommended: no — flat list for MVP.)
- [ ] Should `assignment_type` support "authority grade" resolution? (Recommended: no — ERD only supports specific_position, department_head, manual_at_launch. Authority grade is used for escalation and analytics, not direct assignment.)
- [ ] Should `escalation_position_id` be validated to ensure it exists and is active? (Recommended: yes, with soft validation — warn if position is inactive but allow for flexibility.)
- [ ] Should system-defined stage types be seeded per tenant or shared globally? (Recommended: seeded per tenant on provisioning so tenants can customize display order and deactivate unused ones.)

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
