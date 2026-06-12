# Spec: Stage Lifecycle

> **Number:** 006
> **Date:** 2026-06-12
> **Status:** `completed`
> **Milestone:** M4 — Task Execution & Lifecycle
> **Depends on:** `005-task-execution` (task creation, launch, stage/sub-stage instance tables, assignment resolution, task lifecycle state machine, `TaskStatus`, `StageInstanceStatus`, `SubStageInstanceStatus`, `AssignmentRole` enums, `TaskService`, `AssignmentResolutionService`)
> **Provides APIs:** Stage Advance, Stage Return, Sub-stage Complete, Sub-stage Return, Assignment Override (stage and sub-stage), Stage Completion Note, Stage/Sub-stage History, Return History, Task Timeline
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/003-task-details`, `../frontend/specs/005-workflow-visualization`
> **Author:** Momentum init
> **Branch:** `feat/006-stage-lifecycle`
> **Base branch:** `main`

---

## Problem

Spec 005 established the ability to create tasks from Blueprints, resolve Stage 1 assignees, and manage task-level lifecycle (suspend, resume, cancel). However, once a task is launched and Stage 1 is active, **there is no mechanism to progress the task through its remaining stages**. The system currently:

- **Cannot advance a task to the next stage** — Stage 1 assignees have no way to mark their work complete and pass the task forward. The task is permanently stuck at Stage 1.
- **Cannot return a task to a previous stage** — If Stage 1 assignees find the request incomplete or incorrect, there is no mechanism to send it back to the initiator's stage or any earlier stage with a reason.
- **Cannot complete sub-stages** — If Stage 1 has sub-stages, assignees cannot mark individual sub-stages as done, and the parent stage cannot evaluate whether all required sub-stages are complete before advancing.
- **Cannot override assignments** — If an active stage assignee is unavailable (beyond delegation) or was assigned in error, authorized users have no way to reassign the stage.
- **Cannot complete the task** — When the final stage completes, nothing transitions the task from `active` to `completed`.
- **Cannot view stage history** — There is no API to show which stages the task has passed through, who was assigned, how long each took, and what completion notes were recorded.

Without this spec:
- **SLA & Escalation (Spec 007)** can start timers on Stage 1 but has no events for subsequent stage entries/completions to start/stop timers.
- **Notifications (Spec 008)** cannot notify next-stage assignees because no advancement occurs.
- **Follow-up Board (Spec 010)** cannot show real-time task position because tasks never move past Stage 1.
- **Analytics (Spec 009)** cannot measure stage turnaround times, bottleneck stages, or completion rates because no stage progression data exists.

This is the **operational core of the platform** — the feature that delivers precise, stage-level accountability.

---

## Goal

Deliver the **Task module's stage progression engine** — stage/sub-stage advance, return, completion rule evaluation, assignment override, task completion via final stage, and full stage history API. After this spec, tasks can flow through their entire Blueprint lifecycle from Stage 1 to completion, with returns, re-entries, assignment changes, and immutable history recorded at every step.

All operations live in the Task module, use existing tables created by Spec 005 (`task_stage_instances`, `task_sub_stage_instances`, `task_stage_assignments`), and emit domain events for downstream consumers (SLA, Notification, Audit). No new tables are required — this spec operates on the schema established by 005.

---

## User Stories

### Stage Completion & Advancement

- As a **stage assignee**, I want to submit a completion note for my active stage/sub-stage, so that the next assignees understand what was done and any recommendations.
- As the **system**, I want to evaluate the stage's completion rule (`any_assignee`, `all_assignees`, `lead_assignee`) when an assignee marks their assignment as complete, so that the stage only advances when the rule is satisfied.
- As the **system**, I want to automatically advance the task to the next stage (by `sequence_order`) when the current stage's completion rule is satisfied and all required sub-stages are complete, so that the workflow progresses without manual intervention.
- As the **system**, I want to resolve the next stage's assignees at advancement time using the same assignment resolution logic from Spec 005 (specific position, department head, manual at launch), so that accountability transfers immediately.
- As the **system**, I want to emit domain events (`StageInstanceCompleted`, `StageInstanceAdvanced`, `StageAssignmentCompleted`) when stages advance, so that SLA timers can stop/start and notifications can fire.
- As the **system**, I want to complete the task (`status = completed`, `completed_at = now()`) when the final stage (no outgoing advance transition) completes, so that the task lifecycle reaches its terminal state.

### Sub-stage Completion

- As a **sub-stage assignee**, I want to mark my sub-stage as complete with a completion note, so that the parent stage's completion evaluation can consider it.
- As the **system**, I want to evaluate sub-stage completion rules independently from the parent stage, so that multi-assignee sub-stages follow their own `any_assignee`/`all_assignees`/`lead_assignee` rule.
- As the **system**, I want to activate the next sequential sub-stage when the current one completes, so that sub-stages progress in order.
- As the **system**, I want to prevent the parent stage from advancing until all **required** sub-stages are complete, so that mandatory internal steps cannot be skipped.

### Stage Return

- As an **active stage assignee**, I want to return the task to a previously completed stage with a mandatory written reason, so that the previous assignees can address issues before the workflow continues.
- As the **system**, I want to validate that the return target is defined in the Blueprint's `blueprint_stage_transitions` (transition type = return), so that returns follow the approved workflow.
- As the **system**, I want to create a **new** `task_stage_instance` for the return target (not reuse the old one), so that each entry through a stage is independently tracked and timed.
- As the **system**, I want to mark the current stage instance as `returned` and the returning assignment as the source of the return, so that the return history is clear.
- As the **system**, I want to resolve assignees fresh for the returned-to stage, so that position changes since the previous entry are reflected.
- As the **system**, I want to emit `StageInstanceReturned` and `StageInstanceCreated` events, so that SLA timers reset and return notifications fire.

### Sub-stage Return

- As an **active sub-stage assignee**, I want to return a sub-stage to a previous sub-stage within the same parent stage with a mandatory reason, so that internal step issues can be corrected.
- As the **system**, I want to create a new sub-stage instance for the return target and mark the current sub-stage as `returned`.

### Assignment Override

- As an **authorized user** with `task.override_assignment` capability, I want to reassign one or more assignees of an active stage or sub-stage with a mandatory reason, so that accountability transfers when the original assignee is unavailable or incorrect.
- As the **system**, I want to record `reassigned_at`, `reassigned_by_user_id`, and `reassignment_reason` on the original assignment, so that the override is fully auditable.
- As the **system**, I want to create a new assignment record for the new assignee and resolve their position at assignment time, so that the audit trail is complete.
- As the **system**, I want to emit `StageAssignmentOverridden` event, so that notifications reach both the old and new assignees.

### Stage & Task History

- As an **authorized user**, I want to view the full stage history of a task, so that I can see every stage the task has passed through, in order, with assignees, durations, completion notes, and outcomes.
- As an **authorized user**, I want to view all returns made on a task with reasons, timestamps, who returned it, and to which stage, so that the return pattern is visible.
- As an **authorized user**, I want to view the full task timeline, so that every stage entry, exit, assignment, return, override, and completion is visible in chronological order.

---

## Acceptance Criteria

### Stage Advance (Complete & Progress)

- [x] `POST /api/v1/tasks/{task}/stages/{stageInstance}/complete` — mark the calling user's assignment as complete on an active stage. Request body: `completion_note` (text, optional). Requires: user is an active assignee of the stage instance, task is `active`, stage instance is `active`.
- [x] On individual assignment completion:
  - Set `task_stage_assignments.is_completed = true`, `completed_at = now()`
  - If `completion_note` provided, store on the stage instance `completion_note` (append or last-writer-wins — see Open Questions)
  - Evaluate completion rule:
    - `AnyAssignee` → stage is complete if **any one** required/lead assignee has `is_completed = true`
    - `AllAssignees` → stage is complete if **all** required/lead assignees have `is_completed = true` (optional assignees do not block)
    - `LeadAssignee` → stage is complete if the assignee with `assignment_role = lead` has `is_completed = true`
  - If stage has sub-stages, verify all **required** sub-stages have `status = completed` before allowing stage completion
  - Emit `StageAssignmentCompleted` event
- [x] When completion rule is satisfied:
  - Set stage instance `status = completed`, `exited_at = now()`
  - Emit `StageInstanceCompleted` event
  - Look up the next stage via `blueprint_stage_transitions` where `from_stage_id = current blueprint_stage_id` and `transition_type = advance`
  - If next stage exists:
    - Create new `task_stage_instance` with `status = active`, `entered_at = now()`
    - Create `task_sub_stage_instances` for the next stage's sub-stages (if any); activate the first required sub-stage
    - Resolve and create `task_stage_assignments` for the next stage using the same assignment resolution logic from Spec 005
    - Set `owning_department_id` on the new stage instance based on the first assignee's department
    - Emit `StageInstanceAdvanced`, `StageInstanceCreated`, `StageAssignmentCreated` events
  - If no next stage (final stage):
    - Set `tasks.status = completed`, `tasks.completed_at = now()`
    - Emit `TaskCompleted` event
- [x] Return 422 if user is not an active assignee of the stage
- [x] Return 422 if task is not `active`
- [x] Return 422 if stage instance is not `active`
- [x] Return 422 if required sub-stages are not all completed

### Sub-stage Complete

- [x] `POST /api/v1/tasks/{task}/sub-stages/{subStageInstance}/complete` — mark the calling user's assignment as complete on an active sub-stage. Request body: `completion_note` (text, optional). Requires: user is an active assignee of the sub-stage instance, task is `active`, sub-stage instance is `active`.
- [x] On individual sub-stage assignment completion:
  - Set `task_stage_assignments.is_completed = true`, `completed_at = now()`
  - Evaluate sub-stage completion rule (same logic as stage: `AnyAssignee`, `AllAssignees`, `LeadAssignee`)
  - Emit `SubStageAssignmentCompleted` event
- [x] When sub-stage completion rule is satisfied:
  - Set sub-stage instance `status = completed`, `exited_at = now()`
  - Emit `SubStageInstanceCompleted` event
  - If there is a next sequential sub-stage within the same parent stage: set its `status = active`, `entered_at = now()`. Resolve and create assignments for it. Emit `SubStageInstanceCreated`, `StageAssignmentCreated` events.
  - If no next sub-stage: no automatic stage advance — the stage-level completion endpoint handles stage-level advancement

### Stage Return

- [x] `POST /api/v1/tasks/{task}/stages/{stageInstance}/return` — return the task to an earlier stage. Request body: `target_stage_id` (public_id of the Blueprint stage to return to, required), `reason` (text, required). Requires: user is an active assignee of the current stage, task is `active`, stage instance is `active`.
- [x] Validate `target_stage_id` exists in `blueprint_stage_transitions` as a return transition from the current stage (`from_stage_id = current stage's `blueprint_stage_id`, `to_stage_id = target`, `transition_type = return`)
- [x] On return:
  - Mark current stage instance `status = returned`, `exited_at = now()`, `return_reason = reason`
  - Cancel any pending/active sub-stage instances of the current stage: set `status = returned`, `exited_at = now()`
  - Create a **new** `task_stage_instance` for the target stage with `status = active`, `entered_at = now()`, `sequence_order` copied from Blueprint
  - Create `task_sub_stage_instances` for the target stage's sub-stages (if any)
  - Resolve and create `task_stage_assignments` for the new stage instance (fresh resolution — assignees may have changed)
  - Emit `StageInstanceReturned`, `StageInstanceCreated`, `StageAssignmentCreated` events
- [x] Return 422 if no valid return transition exists for the target stage
- [x] Return 422 if user is not an active assignee of the current stage

### Sub-stage Return

- [x] `POST /api/v1/tasks/{task}/sub-stages/{subStageInstance}/return` — return to an earlier sub-stage within the same parent stage. Request body: `target_sub_stage_id` (public_id of the Blueprint sub-stage to return to, required), `reason` (text, required). Requires: user is an active assignee of the current sub-stage, task is `active`, sub-stage instance is `active`.
- [x] Validate target sub-stage belongs to the same parent stage and has a lower `sequence_order`
- [x] On return:
  - Mark current sub-stage instance `status = returned`, `exited_at = now()`
  - Create a **new** `task_sub_stage_instance` for the target sub-stage with `status = active`, `entered_at = now()`
  - Resolve and create `task_stage_assignments` for the new sub-stage instance
  - Emit `SubStageInstanceReturned`, `SubStageInstanceCreated`, `StageAssignmentCreated` events

### Assignment Override

- [x] `POST /api/v1/tasks/{task}/stages/{stageInstance}/override-assignment` — reassign one or more assignees of an active stage. Request body: `assignments` (array of `{current_user_id, new_user_id}`), `reason` (text, required). Requires: `task.override_assignment` capability, task is `active`, stage instance is `active`.
- [x] `POST /api/v1/tasks/{task}/sub-stages/{subStageInstance}/override-assignment` — same as above for sub-stage assignments.
- [x] On override:
  - For each reassigned user: set `reassigned_at = now()`, `reassigned_by_user_id`, `reassignment_reason` on the old assignment
  - Create new `task_stage_assignment` for the new user with `assigned_at = now()`, `position_id` resolved from new user's current position
  - Check for active delegation for the new user; if delegated, assign to delegate with `delegated_from_user_id`
  - Emit `StageAssignmentOverridden` event per reassignment
- [x] Return 422 if `current_user_id` is not an active (non-completed, non-reassigned) assignee of the stage/sub-stage
- [x] Return 403 if caller lacks `task.override_assignment` capability

### Stage History & Timeline

- [x] `GET /api/v1/tasks/{task}/stages` — list all stage instances for a task, ordered by `created_at`. Returns: stage instance details, Blueprint stage name, type, assignees, status, entered_at, exited_at, completion_note, return_reason. ABAC visibility enforced (user must be able to view the task). Requires authentication.
- [x] `GET /api/v1/tasks/{task}/stages/{stageInstance}` — show a single stage instance with full detail including assignments and sub-stage instances.
- [x] `GET /api/v1/tasks/{task}/returns` — list all stage instances with `status = returned`, ordered by `exited_at`. Shows who returned, reason, from which stage to which stage.
- [x] `GET /api/v1/tasks/{task}/timeline` — chronological list of all stage lifecycle events: stage entries, exits, assignments, completions, returns, overrides. Constructed from stage instance and assignment data, ordered by timestamp.
- [x] All history endpoints use `public_id` only — never expose internal `id`
- [x] All history endpoints enforce ABAC: user must have visibility to the parent task per `TaskVisibilityScope`

### General

- [x] All endpoints follow `/api/v1/tasks/` prefix
- [x] All responses use API Resources with `public_id` only — never expose internal `id`
- [x] All mutating endpoints require authentication and ABAC enforcement
- [x] Domain events emitted for all mutating actions: `StageAssignmentCompleted`, `StageInstanceCompleted`, `StageInstanceAdvanced`, `StageInstanceReturned`, `SubStageAssignmentCompleted`, `SubStageInstanceCompleted`, `SubStageInstanceReturned`, `SubStageInstanceCreated` (on advance/return), `StageAssignmentCreated` (on advance/return/override), `StageAssignmentOverridden`, `TaskCompleted`
- [x] All domain events implement `ShouldDispatchAfterCommit`
- [x] Returned stages create **new** instances (one row per stage entry) — history is never overwritten
- [x] Feature tests cover: stage complete with all three completion rules, advance to next stage, final stage completes task, return to valid target, return with invalid target rejected, sub-stage complete and auto-activate next, sub-stage return, assignment override with capability check, assignment override denied without capability, stage history listing, return history listing, timeline endpoint, concurrent completion race condition (two assignees completing simultaneously under `AllAssignees`)

---

## Non-Functional Requirements

### Pagination

- Endpoints listing `task_stage_instances` (stage history) return **full list** (expected < 50 instances per task — a task rarely has more than 20 stages even with returns). See `coding-standards.md` — Exception: Small Stable Tables.
- Endpoints listing `task_stage_assignments` return **full list** (expected < 100 per task).
- Timeline endpoint returns **full list** (aggregated from stage instances + assignments, expected < 200 entries per task).
- No cursor pagination needed for this spec — all queries are scoped to a single task.

### Caching

- **No caching for stage instance data** — stage progression is write-heavy with frequent status changes; caching would cause stale state and race conditions. Direct DB queries are fast (single task scope with indexed foreign keys).
- **Blueprint structure** is read from the existing `{tenant_slug}:blueprint:{blueprint_public_id}:structure` cache (established by Spec 004) during stage advance/return to look up transitions and next-stage definitions.
- All cache keys remain tenant-prefixed per `coding-standards.md`.

### Rate Limiting

- Mutating endpoints (stage complete, stage return, sub-stage complete, sub-stage return, assignment override): `RateLimits::MUTATE` (30/min per user)
- Read endpoints (stage history, return history, timeline): `RateLimits::LIST` (60/min per user)
- See `coding-standards.md` — Rate Limiting for `HasRateLimiting` trait usage.

### Database Transactions

- **Stage advance** (complete + create next stage + assignments): `DB::transaction()` required — updates current stage status, creates next stage instance, creates sub-stage instances, creates assignments, optionally completes the task.
- **Stage return** (mark returned + create new stage + assignments): `DB::transaction()` required — updates current stage status, cancels sub-stages, creates target stage instance, creates sub-stage instances, creates assignments.
- **Sub-stage advance** (complete + activate next): `DB::transaction()` required — updates current sub-stage status, creates/activates next sub-stage instance, creates assignments.
- **Sub-stage return**: `DB::transaction()` required — same pattern as stage return but within sub-stages.
- **Assignment override**: `DB::transaction()` required — updates old assignment, creates new assignment.
- **Individual assignment completion** (without stage advance): single write — no transaction needed if only updating `is_completed`.
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `task` (reuse from Spec 005)
- All service methods use try/catch with `Log::channel('task')`
- Structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`
- New domain exceptions registered in `bootstrap/app.php`:
  - `StageNotActiveException` — attempt to complete/return a non-active stage
  - `SubStageNotActiveException` — attempt to complete/return a non-active sub-stage
  - `UserNotAssigneeException` — user is not an active assignee of the stage/sub-stage
  - `InvalidReturnTargetException` — return target not defined in Blueprint transitions
  - `RequiredSubStagesIncompleteException` — attempt to complete stage before all required sub-stages are done
  - `InvalidSubStageReturnTargetException` — sub-stage return target not in the same parent or not earlier in sequence
  - `AssigneeNotFoundForOverrideException` — current_user_id is not a valid active assignee
- See `coding-standards.md` — Error Handling & Logging.

### Enums

- **No new enums required** — this spec uses existing enums:
  - `StageInstanceStatus` (Pending, Active, Completed, Returned, Skipped)
  - `SubStageInstanceStatus` (Pending, Active, Completed, Returned)
  - `TaskStatus` (Draft, Active, Suspended, Completed, Cancelled)
  - `CompletionRule` (AnyAssignee, AllAssignees, LeadAssignee)
  - `AssignmentRole` (Required, Optional, Lead)
  - `TransitionType` (Advance, Return)
- All status transitions must use enum cases — never raw integers.
- See `coding-standards.md` — Enum Usage.

### Queue Jobs

- No queue jobs required — stage progression is synchronous and involves small, indexed writes (1-5 assignments per stage, single stage instance creation).
- All domain events implement `ShouldDispatchAfterCommit` — listeners in Notification (Spec 008), Tracking/SLA (Spec 007), and Audit (Spec 015) decide independently whether to queue their work.
- See `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **SLA timer start/stop on stage entry/exit** — Spec 007 (SLA & Escalation) owns `sla_timer_instances`; this spec only emits events that 007 consumes.
- **Notification delivery on stage advance/return/override** — Spec 008 (Notifications) consumes domain events; this spec only emits them.
- **Audit trail persistence** — Spec 015 (Audit Trail) consumes domain events to write `audit_events`; this spec only emits events.
- **Insert ad-hoc stage** — deferred to V2. MVP only supports stages defined in the Blueprint at creation.
- **Skip optional stage** — deferred to V2. MVP has no optional stage skip mechanism.
- **Branching conditions** — deferred to V2. MVP advances strictly to the next `sequence_order`.
- **Parallel stage groups** — deferred to V2. All stages are strictly sequential.
- **Stage form (structured output)** — deferred to V2. MVP uses free-text `completion_note` only.
- **Stage-level blocking/pause** — deferred to V2 (use task-level suspend instead).
- **Confidential participant management** — Spec 017.
- **Comments** — Spec 013.
- **External references** — Spec 014.
- **Document attachments on stage output** — Spec 012. This spec's completion note is text-only.

---

## Open Questions

- [x] **Completion note strategy:** When multiple assignees complete a stage (under `AllAssignees` rule), should each assignee's note be stored separately (one per assignment) or merged into the stage instance's `completion_note`? **Resolved:** Store on each assignment record (add a `completion_note` text column to `task_stage_assignments`), and keep the stage-level `completion_note` as an aggregation or final summary set by the last completing assignee. This preserves individual accountability.
- [x] **Sub-stage return transitions:** Blueprint transitions (`blueprint_stage_transitions`) only define stage-to-stage transitions. Sub-stage returns should be simpler: any active sub-stage can return to any earlier sub-stage within the same parent stage by `sequence_order`. **Resolved:** Yes, use `sequence_order` comparison — no explicit sub-stage transition table needed.
- [x] **Manual-at-launch stages on re-entry:** When a stage with `assignment_type = manual_at_launch` is re-entered via return, should the system reuse the original manual assignments or require fresh manual input? **Resolved:** Reuse the original manual assignments from the task's launch data, since the user selected them at task creation. If override is needed, the authorized user can use the assignment override endpoint after re-entry.
- [x] **Advance transition lookup:** If no explicit advance transition exists in `blueprint_stage_transitions` for the current stage, should the system fall back to `sequence_order + 1`? Or must every advance be explicitly defined? **Resolved:** Fall back to next `sequence_order` if no explicit advance transition. This matches the Feature Inventory note: "MVP: advance is always to next sequence_order." Explicit transitions allow specific routing; absence means natural sequential flow.
- [x] **New capability `task.advance_stage`:** Should completing/advancing a stage require a dedicated capability, or is it implicit for any assigned user? **Resolved:** Implicit — only the assigned user(s) can complete a stage. The assignment itself is the authorization. No separate `task.advance_stage` capability needed.
- [x] **New column `completion_note` on `task_stage_assignments`:** Should we add this column to the existing `task_stage_assignments` table for per-assignee notes? **Resolved:** Yes — this is a minor additive migration.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
