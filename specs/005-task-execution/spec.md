# Spec: Task Execution

> **Number:** 005
> **Date:** 2026-06-11
> **Status:** `completed`
> **Milestone:** M4 — Task Execution & Lifecycle
> **Depends on:** `001-platform-tenancy` (tenant DB, base models, `public_id`), `002-organization-structure` (departments, positions, authority grades, working calendars), `003-iam-abac` (users, capabilities, ABAC policy engine, position assignments, delegations), `004-blueprint-engine` (blueprints, stages, sub-stages, transitions, SLA policies, blueprint lock mechanism)
> **Provides APIs:** Task Priority CRUD, Task CRUD (create draft, update draft, launch, show, list), Task Lifecycle (suspend, resume, cancel), Assignment Resolution Service, Manual Assignment at Launch
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/002-task-board`, `../frontend/specs/003-task-details`
> **Author:** Momentum init
> **Branch:** `feat/005-task-execution`
> **Base branch:** `main`

---

## Problem

The Blueprint Engine (Spec 004) established reusable workflow templates — but templates alone cannot execute work. Without a **Task Execution** module, no one can create an instance of work from a Blueprint, assign it to real people, or track its lifecycle state.

Today, after completing M3, the platform can define *how* work should flow (stages, SLAs, assignment rules, transitions). But it cannot:

- **Create a task** — select a Blueprint, fill in context (title, description, priority, due date, classification), and save as draft or launch immediately.
- **Resolve assignees** — translate Blueprint assignment rules (specific position, department head, manual at launch) into actual user assignments at runtime by consulting Organization and IAM modules.
- **Lock the Blueprint** — the first task launched from a Blueprint must trigger `is_locked = true`, making the Blueprint immutable for accountability.
- **Manage task lifecycle** — suspend, resume, or cancel tasks with proper state transitions, timestamps, and mandatory reasons.
- **Track the initiator** — record who created the task, distinct from all stage assignees.
- **Manage task priorities** — tenant-configurable priority levels (Routine, Urgent, Critical) that tasks are assigned at creation.
- **Set classification level** — public, internal, or confidential visibility per task.

Without this spec:
- **Stage Lifecycle (Spec 006)** cannot progress tasks through stages — there are no stage instances to progress.
- **SLA & Escalation (Spec 007)** cannot start timers — there are no active stage instances.
- **Comments (Spec 013)** and **External References (Spec 014)** have no task entity to attach to.
- **Notifications (Spec 008)** have no assignment events to trigger alerts.

---

## Goal

Deliver the **Task module's creation and launch capability** — task priorities, task CRUD, assignment resolution, blueprint lock trigger, and task lifecycle state management (draft → active → suspended/completed/cancelled). After this spec, authorized users can create tasks from active blueprints, the system resolves Stage 1 assignees at launch, and task lifecycle operations (suspend, resume, cancel) are fully functional.

All data lives in the tenant DB. All endpoints use `public_id`. All mutating endpoints enforce ABAC via `RequireCapability`. Stage *progression* (advance, return, completion) is handled by Spec 006 — this spec only creates the initial stage instance and assignment at launch.

---

## User Stories

### Task Priorities

- As a **tenant admin**, I want to manage task priority levels (create, update, deactivate, reactivate), so that my organization can customize priorities beyond the default three.
- As a **tenant admin**, I want to set a default priority and display order, so that task creation forms pre-select the most common priority.
- As an **authorized user**, I want to view available priorities when creating a task, so that I can select the appropriate urgency level.

### Task Creation & Draft

- As an **authorized user**, I want to create a task by selecting an active Blueprint, so that the task follows a defined lifecycle workflow.
- As an **authorized user**, I want to set the task's title (Arabic required, English optional), description, priority, classification level, and optional due date, so that all context is captured for assignees.
- As an **authorized user**, I want to save a task as draft, so that I can build it incrementally before launching.
- As an **authorized user**, I want to update a draft task's metadata, so that I can refine it before launch.
- As an **authorized user**, I want to delete a draft task, so that I can clean up mistakes before launch.
- As an **authorized user**, I want to provide manual assignees at draft time for any stage/sub-stage marked as "Manual at Launch", so that the task is ready to launch.

### Task Launch

- As an **authorized user**, I want to launch a task, so that Stage 1 assignees are resolved and notified, SLA timers can begin (Spec 007), and the workflow is officially in progress.
- As the **system**, I want to lock the Blueprint on first task launch (`is_locked = true`), so that the Blueprint's stage definitions become immutable for accountability.
- As the **system**, I want to create a `task_stage_instance` for Stage 1 at launch, so that the task has an active stage for progression.
- As the **system**, I want to create `task_sub_stage_instances` for Stage 1's sub-stages (if any) at launch, so that sub-stage tracking begins immediately.
- As the **system**, I want to resolve Stage 1 assignees based on the Blueprint's `assignment_type` (specific position → current occupant, department head → head position occupant, manual at launch → user-provided), so that accountability is established from the start.
- As the **system**, I want to check for active delegations during assignment resolution and assign to the delegate if applicable, so that out-of-office coverage works automatically.
- As the **system**, I want to validate that all required positions are filled before launch, so that no task launches with unresolvable assignees.
- As the **system**, I want to record the task initiator, so that they receive lifecycle notifications and retain visibility throughout the task.

### Task Viewing

- As an **authorized user**, I want to view a task's overview (current status, priority, classification, due date, initiator, Blueprint info), so that I can understand the task at a glance.
- As an **authorized user**, I want to list tasks with filters (status, priority, Blueprint, classification, initiator, date range), so that I can find specific tasks.
- As the **system**, I want to enforce ABAC visibility rules when listing/viewing tasks, so that users only see tasks they are authorized to access.

### Task Lifecycle

- As an **authorized user** with `task.suspend_resume` capability, I want to suspend an active task with a mandatory reason, so that all SLA timers pause and assignees are informed.
- As an **authorized user** with `task.suspend_resume` capability, I want to resume a suspended task, so that SLA timers restart and assignees are notified.
- As an **authorized user** with `task.cancel` capability, I want to cancel a task (draft or active) with a mandatory reason, so that the task is terminated and all parties are informed.

---

## Acceptance Criteria

### Task Priorities

- [x] `task_priorities` table in tenant DB with columns: `id`, `public_id` (UUID v7, unique), `name_en`, `name_ar`, `severity_rank` (smallint, lower = more severe), `color_code` (varchar, nullable), `is_default` (boolean, default false), `is_active` (boolean, default true), `display_order` (smallint, default 0), `created_at`, `updated_at`, `deleted_at`
- [x] Platform ships 3 default priorities via `TenantDatabaseSeeder`: Critical (rank 1), Urgent (rank 2), Routine (rank 3, default)
- [x] `name_ar` required; `name_en` optional (system copies `name_ar` if empty)
- [x] Only one priority can be `is_default = true` at a time; setting a new default unsets the old one
- [x] `GET /api/v1/tasks/priorities` — list all active priorities ordered by `display_order`. Full list (expected < 20). Requires authentication.
- [x] `POST /api/v1/tasks/priorities` — create priority. Requires `task.manage_priorities` capability.
- [x] `PUT /api/v1/tasks/priorities/{priority}` — update priority. Requires `task.manage_priorities`.
- [x] `POST /api/v1/tasks/priorities/{priority}/deactivate` — set `is_active = false`. Requires `task.manage_priorities`.
- [x] `POST /api/v1/tasks/priorities/{priority}/reactivate` — set `is_active = true`. Requires `task.manage_priorities`.

### Tasks

- [x] `tasks` table in tenant DB with columns: `id`, `public_id` (UUID v7, unique), `blueprint_id` (FK blueprints), `priority_id` (FK task_priorities), `title_en` (nullable), `title_ar` (required), `description_en` (text, nullable), `description_ar` (text, required), `classification_level` (TINYINT: 1=public, 2=internal, 3=confidential, default 1), `initiator_user_id` (FK users), `status` (TINYINT: 1=draft, 2=active, 3=suspended, 4=completed, 5=cancelled, default 1), `due_date` (date, nullable), `created_at`, `launched_at` (nullable), `suspended_at` (nullable), `resumed_at` (nullable), `completed_at` (nullable), `cancelled_at` (nullable), `cancellation_reason` (text, nullable), `archived_at` (nullable), `archived_by_user_id` (nullable FK users), `deleted_at`
- [x] `title_ar` required; `title_en` optional (system copies `title_ar` if empty)
- [x] `description_ar` required; `description_en` optional (system copies `description_ar` if empty)
- [x] `classification_level = 3` (confidential) requires `task.classify.confidential` capability
- [x] `POST /api/v1/tasks` — create task in `draft` status. Requires: authenticated user, `blueprint_id` must reference an active Blueprint. Request body: `blueprint_id`, `priority_id`, `title_ar`, `title_en` (optional), `description_ar`, `description_en` (optional), `classification_level` (optional, default 1), `due_date` (optional), `manual_assignments` (optional array of `{blueprint_stage_id, user_ids}` for stages with `assignment_type = manual_at_launch`)
- [x] `GET /api/v1/tasks/{task}` — show task with current status, priority, classification, Blueprint info, initiator info. ABAC visibility enforced. Requires authentication.
- [x] `GET /api/v1/tasks` — list tasks with cursor pagination. Filters: `status`, `priority_id`, `blueprint_id`, `classification_level`, `initiator_user_id`, `created_from`, `created_to`, `due_from`, `due_to`, `search` (title). ABAC visibility enforced. Requires authentication.
- [x] `PUT /api/v1/tasks/{task}` — update draft task metadata. Only allowed when `status = draft`. Requires: task initiator or user with `task.manage` capability.
- [x] `DELETE /api/v1/tasks/{task}` — soft-delete a draft task. Only allowed when `status = draft`. Requires: task initiator.

### Task Launch

- [x] `POST /api/v1/tasks/{task}/launch` — launch a draft task. Requires: task initiator or user with `task.manage` capability. Validates:
  - Task is in `draft` status
  - Blueprint is active (`is_active = true`)
  - Blueprint has at least one stage
  - All Stage 1 (and Stage 1 sub-stage) assignments are resolvable — positions filled, or manual assignments provided
  - If any Stage 1 stage/sub-stage uses `assignment_type = manual_at_launch`, manual assignments must be provided (either at creation or updated before launch)
- [x] On launch:
  - Set `status = active`, `launched_at = now()`
  - Lock Blueprint if not already locked: `blueprints.is_locked = true`
  - Create `task_stage_instance` for Stage 1 (lowest `sequence_order`), status `active`, `entered_at = now()`
  - Create `task_sub_stage_instances` for Stage 1's sub-stages (if any), first required sub-stage set to `active`
  - Resolve and create `task_stage_assignments` for Stage 1 assignees:
    - `assignment_type = 1` (specific_position): find current occupant of `assigned_position_id` via `user_position_assignments` (active, primary)
    - `assignment_type = 2` (department_head): find `is_department_head = true` position in `assigned_department_id`, then find current occupant
    - `assignment_type = 3` (manual_at_launch): use user IDs provided in `manual_assignments`
  - For each resolved assignee, check for active delegation via IAM; if delegated, assign to delegate and record `delegated_from_user_id`
  - Store `position_id` on each assignment (position at time of assignment, for audit)
  - Set `owning_department_id` on stage instance (resolved from the first assignee's department)
  - Emit domain events: `TaskLaunched`, `StageInstanceCreated`, `StageAssignmentCreated`
  - Emit `BlueprintLocked` event if Blueprint was locked by this launch

### Task Lifecycle

- [x] `POST /api/v1/tasks/{task}/suspend` — suspend an active task. Requires `task.suspend_resume` capability. Request body: `reason` (required). On suspend: set `status = suspended`, `suspended_at = now()`. Emit `TaskSuspended` event.
- [x] `POST /api/v1/tasks/{task}/resume` — resume a suspended task. Requires `task.suspend_resume` capability. On resume: set `status = active`, `resumed_at = now()`. Emit `TaskResumed` event.
- [x] `POST /api/v1/tasks/{task}/cancel` — cancel a draft or active task. Requires `task.cancel` capability. Request body: `reason` (required). On cancel: set `status = cancelled`, `cancelled_at = now()`, `cancellation_reason = reason`. Emit `TaskCancelled` event.
- [x] Tasks in `completed`, `cancelled`, or `suspended` (for suspend) status cannot be re-suspended, re-cancelled, or re-launched.
- [x] State transition validation:
  - `draft` → `active` (launch), `cancelled` (cancel)
  - `active` → `suspended` (suspend), `cancelled` (cancel), `completed` (Spec 006 — final stage completion)
  - `suspended` → `active` (resume), `cancelled` (cancel)
  - `completed` → *(no transitions in MVP)*
  - `cancelled` → *(no transitions in MVP)*

### Task Stage Instances

- [x] `task_stage_instances` table in tenant DB with columns: `id`, `task_id` (FK tasks), `blueprint_stage_id` (FK blueprint_stages), `sequence_order` (smallint, copied from Blueprint), `owning_department_id` (nullable FK departments), `completion_rule` (TINYINT, copied from Blueprint stage), `status` (TINYINT: 1=pending, 2=active, 3=completed, 4=returned, 5=skipped, default 1), `entered_at` (nullable), `exited_at` (nullable), `completion_note` (text, nullable), `return_reason` (text, nullable), `created_at`
- [x] One row per stage entry — a returned stage creates a new instance on re-entry (Spec 006)
- [x] `owning_department_id` frozen at stage entry based on the first assignee's department

### Task Sub-Stage Instances

- [x] `task_sub_stage_instances` table in tenant DB with columns: `id`, `task_id` (FK tasks), `parent_stage_instance_id` (FK task_stage_instances), `blueprint_sub_stage_id` (FK blueprint_sub_stages), `sequence_order` (smallint), `owning_department_id` (nullable FK departments), `is_required` (boolean, copied from Blueprint), `completion_rule` (TINYINT), `status` (TINYINT: 1=pending, 2=active, 3=completed, 4=returned, default 1), `entered_at` (nullable), `exited_at` (nullable), `completion_note` (text, nullable), `created_at`

### Task Stage Assignments

- [x] `task_stage_assignments` table in tenant DB with columns: `id`, `task_id` (FK tasks), `stage_instance_id` (nullable FK task_stage_instances), `sub_stage_instance_id` (nullable FK task_sub_stage_instances), `user_id` (FK users), `position_id` (nullable FK positions), `delegated_from_user_id` (nullable FK users), `assignment_role` (TINYINT: 1=required, 2=optional, 3=lead, default 1), `is_completed` (boolean, default false), `assigned_at` (timestamp), `completed_at` (nullable), `reassigned_at` (nullable), `reassigned_by_user_id` (nullable FK users), `reassignment_reason` (text, nullable)
- [x] Either `stage_instance_id` or `sub_stage_instance_id` is populated (not both)
- [x] `position_id` records the position at assignment time for audit (even if user transfers later)
- [x] `delegated_from_user_id` recorded when assignment via delegation

### General

- [x] All endpoints follow `/api/v1/tasks/` prefix
- [x] All responses use API Resources with `public_id` only — never expose internal `id`
- [x] All mutating endpoints require authentication
- [x] Bilingual fields: `title_ar` / `description_ar` required; `title_en` / `description_en` optional, system copies Arabic if empty
- [x] Domain events emitted for all mutating actions: `TaskCreated`, `TaskUpdated`, `TaskLaunched`, `TaskSuspended`, `TaskResumed`, `TaskCancelled`, `BlueprintLocked` (delegated from Blueprint module), `StageInstanceCreated`, `SubStageInstanceCreated`, `StageAssignmentCreated`
- [x] All domain events implement `ShouldDispatchAfterCommit`
- [x] Feature tests cover: task priority CRUD, task create/update/delete draft, task launch with all three assignment types, blueprint lock on first launch, task suspend/resume/cancel, state transition validation, ABAC visibility enforcement, assignment resolution with delegation, launch validation failures (vacant position, inactive blueprint, no stages)

---

## Non-Functional Requirements

### Pagination

- Endpoints listing `tasks` use **cursor pagination** (expected > 1000 rows per tenant). See `coding-standards.md` — Pagination Strategy.
- Endpoints listing `task_priorities` return **full list** (expected < 20 rows). See `coding-standards.md` — Exception: Small Stable Tables.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- Task priority catalog is cached at `{tenant_slug}:task:priorities:all` with TTL 300s (warm tier). Invalidated on any priority create/update/deactivate/reactivate event.
- Individual task data is **not** cached (changes frequently, paginated queries are fast enough).
- Blueprint structure is read from the existing `{tenant_slug}:blueprint:{blueprint_public_id}:structure` cache (established by Spec 004) during task creation and launch.
- All cache keys are tenant-prefixed per `coding-standards.md` — Caching (Redis / phpredis).

### Rate Limiting

- Mutating endpoints (task create, update, launch, suspend, resume, cancel): `RateLimits::MUTATE` (30/min per user)
- List endpoints (task list, priority list): `RateLimits::LIST` (60/min per user)
- See `coding-standards.md` — Rate Limiting for `HasRateLimiting` trait usage.

### Database Transactions

- **Task launch**: `DB::transaction()` required — updates task status, locks Blueprint (if needed), creates stage instance(s), creates sub-stage instance(s), creates assignments. This is the most critical transaction in the spec.
- **Task suspend**: `DB::transaction()` required — updates task status and `suspended_at`.
- **Task resume**: `DB::transaction()` required — updates task status and `resumed_at`.
- **Task cancel**: `DB::transaction()` required — updates task status, `cancelled_at`, and `cancellation_reason`.
- **Priority default swap**: `DB::transaction()` required — unsets old default, sets new default.
- Single create/update of task draft or priority: no transaction needed (single write operations).
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `task`
- All service methods use try/catch with `Log::channel('task')`
- Structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`
- Domain exceptions registered in `bootstrap/app.php`:
  - `TaskNotDraftException` — attempt to update/launch a non-draft task
  - `TaskNotActiveException` — attempt to suspend/cancel a non-active task
  - `TaskNotSuspendedException` — attempt to resume a non-suspended task
  - `BlueprintNotActiveException` — attempt to create/launch task from inactive Blueprint
  - `BlueprintHasNoStagesException` — attempt to launch task from Blueprint without stages
  - `UnresolvableAssignmentException` — position is vacant, cannot resolve assignee for launch
  - `MissingManualAssignmentException` — manual-at-launch stage has no provided assignees
  - `InvalidTaskStateTransitionException` — invalid lifecycle state change attempt
  - `TaskAlreadyCancelledException` — attempt to cancel an already cancelled task
- See `coding-standards.md` — Error Handling & Logging.

### Enums

- `TaskStatus` enum in `app/Modules/Task/Enums/TaskStatus.php`:
  - `Draft = 1`, `Active = 2`, `Suspended = 3`, `Completed = 4`, `Cancelled = 5`
- `ClassificationLevel` enum in `app/Modules/Task/Enums/ClassificationLevel.php`:
  - `Public = 1`, `Internal = 2`, `Confidential = 3`
- `StageInstanceStatus` enum in `app/Modules/Task/Enums/StageInstanceStatus.php`:
  - `Pending = 1`, `Active = 2`, `Completed = 3`, `Returned = 4`, `Skipped = 5`
- `SubStageInstanceStatus` enum in `app/Modules/Task/Enums/SubStageInstanceStatus.php`:
  - `Pending = 1`, `Active = 2`, `Completed = 3`, `Returned = 4`
- `AssignmentRole` enum in `app/Modules/Task/Enums/AssignmentRole.php`:
  - `Required = 1`, `Optional = 2`, `Lead = 3`
- Reuse `AssignmentType`, `AssignmentCardinality`, `CompletionRule` from `app/Modules/Blueprint/Enums/` — do not duplicate.
- All enums used in form requests via `Rule::enum(ClassName::class)` and in service logic — never raw integers.
- See `coding-standards.md` — Enum Usage.

### Queue Jobs

- No queue jobs required for this spec — all operations are synchronous CRUD and state transitions.
- Task launch assignment resolution is fast enough for synchronous execution (resolves 1-5 assignees from indexed tables).
- All domain events implement `ShouldDispatchAfterCommit` (consumed by Notification module in Spec 008, Tracking/SLA in Spec 007, and Audit in Spec 015).
- See `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **Stage progression (advance/return)** — Spec 006 (Stage Lifecycle) handles stage advancement, return, completion, and assignment override.
- **SLA timer creation and management** — Spec 007 (SLA & Escalation) consumes `StageInstanceCreated` events to start timers; this spec only creates the stage instance.
- **Notification delivery** — Spec 008 (Notifications) consumes domain events (`TaskLaunched`, `StageAssignmentCreated`, etc.) to send alerts; this spec only emits events.
- **Comments** — Spec 013 (Comments & Collaboration) handles task-level commenting.
- **External references** — Spec 014 (External References) handles linking tasks to external identifiers.
- **Document attachments at creation** — Spec 012 (Documents & Attachments) handles file uploads; this spec accepts no files.
- **Confidential participant management** — Spec 017 (Confidentiality & Access) handles adding/removing confidential participants. This spec only stores `classification_level` on the task.
- **Task duplication** — deferred to V2.
- **Recurring tasks** — deferred to V2.
- **Task-to-task linking** — deferred to V2.
- **Task reopening** — deferred to V2.
- **Parallel stages** — deferred to V2.
- **Branching conditions** — deferred to V2.
- **Stage forms / exit requirements** — deferred to V2.
- **Full task visibility enforcement with all ABAC rules** — MVP will implement basic ABAC (initiator, assignee, `task.view.organization`, `task.view.department_touched`). Full confidential visibility rules and monitoring scope integration are handled in Specs 017 and 010 respectively.
- **Archiving** — logical archive (`archived_at` column exists) but archiving logic is deferred.

---

## Open Questions

- [x] **New capability `task.manage_priorities`:** Should this be a new capability added to the seeder, or should `blueprint.manage` cover task priority management too? **Resolved:** New `task.manage_priorities` capability — priorities are a Task module concern, not Blueprint. Added to `CapabilitySeeder`.
- [x] **New capability `task.create`:** Should task creation require a dedicated `task.create` capability, or should any authenticated `internal_user` / `tenant_admin` be allowed to create tasks? **Resolved:** Any authenticated `internal_user` or `tenant_admin` can create tasks — task creation is a universal action. Classification to confidential requires `task.classify.confidential`.
- [x] **New capability `task.manage`:** Should there be a `task.manage` capability for updating/deleting other users' draft tasks, or should only the task initiator manage their own drafts? **Resolved:** Yes — add `task.manage` for tenant admins who need to clean up orphan drafts; initiator can always manage their own.
- [x] **Suspension reason storage:** Should we add a `suspension_reason` column, or store suspension reasons in audit events only? **Resolved:** Add `suspension_reason` text column to `tasks` table for direct query access, mirroring `cancellation_reason`.
- [x] **Task list ABAC filtering:** Should task list filtering use inline WHERE clause or post-query filter? **Resolved:** Inline query filter via `TaskVisibilityScope` — post-query filtering wastes DB reads on large tables.
- [x] **Default priority enforcement:** Should `priority_id` be required or use the system default? **Resolved:** `priority_id` optional; service layer selects `is_default = true` priority automatically if omitted.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
