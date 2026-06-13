# Spec: SLA Escalation

> **Number:** 007
> **Date:** 2026-06-13
> **Status:** `completed`
> **Milestone:** M5 — SLA, Escalation & Notifications
> **Depends on:** `002-organization-structure` (positions, reporting lines, working calendars, public holidays), `003-iam-abac` (users, capabilities, monitoring scopes, ABAC policy engine), `004-blueprint-engine` (SLA policies, stage/sub-stage SLA assignment, escalation position override), `005-task-execution` (tasks, stage/sub-stage instances, task suspension/resume/cancel events), `006-stage-lifecycle` (stage/sub-stage completion, return, assignment override, timeline events)
> **Provides APIs:** SLA timer health read APIs, timer list (cursor-paginated), escalation list/show APIs, manual escalation create API, escalation resolution API
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/006-follow-up-center`
> **Author:** Momentum init
> **Branch:** `feat/007-sla-escalation`
> **Base branch:** `main`

---

## Problem

Specs 005 and 006 let tasks launch and move through stages, but the platform still cannot enforce the time accountability promised by Gov TMS. Stage assignees and follow-up users have no authoritative countdown, warning state, breach state, or escalation record tied to the exact stage or sub-stage that is blocking progress.

Today, SLA policy definitions exist on Blueprint stages and sub-stages, but there is no runtime SLA engine to:

- start timers when a stage or sub-stage becomes active
- calculate warning and deadline timestamps using working calendars and public holidays
- pause timers when the whole task is suspended and resume them accurately
- stop timers when stage or sub-stage work completes, returns, cancels, or the task completes
- detect warning and breach thresholds
- auto-escalate breached work to the configured escalation position or reporting-line manager
- allow authorized follow-up users to manually escalate at-risk work before breach
- resolve and track escalation actions without modifying Task module data

Without this spec, the follow-up center cannot reliably show green/amber/red SLA health, managers cannot see actionable escalations, and downstream notification/analytics modules have no stable source of SLA or escalation truth.

---

## Goal

Deliver the Tracking & SLA module's runtime SLA engine for active task stages and sub-stages. The system will create and maintain SLA timer instances from Task module events, calculate working-calendar-aware warning/deadline timestamps, detect threshold crossings, create automatic and manual escalations, and expose read APIs for SLA health and escalation management.

The module observes Task lifecycle events but does not write Task tables. Notification delivery is handled by Spec 008; this spec emits warning, breach, and escalation events that notifications can consume.

---

## User Stories

### SLA Timers

- As a **stage assignee**, I want to see how much SLA time remains on my active stage or sub-stage, so that I can prioritize urgent work.
- As a **follow-up specialist**, I want each active stage and sub-stage to have a reliable SLA health state, so that I can focus on at-risk and breached work.
- As the **system**, I want to start an SLA timer when a stage or sub-stage becomes active, so that accountability begins at the point of assignment.
- As the **system**, I want to calculate deadlines using the tenant working calendar and public holidays, so that non-working days do not count against assignees.
- As the **system**, I want to pause active timers when a task is suspended and resume them when the task resumes, so that suspended time is not counted as SLA delay.
- As the **system**, I want to complete timers when the accountable stage or sub-stage completes, returns, or is cancelled, so that stale timers do not continue to breach.

### Warnings & Breaches

- As a **stage assignee**, I want to receive a warning when my SLA threshold is approaching, so that I can act before the stage breaches.
- As a **manager**, I want breached work to be escalated to the responsible reporting line, so that bottlenecks are surfaced immediately.
- As the **system**, I want to emit warning and breach events only once per timer, so that notification and audit consumers do not create duplicate records.

### Escalations

- As a **follow-up specialist**, I want to manually escalate an at-risk stage with a mandatory reason, so that leadership can intervene before the SLA is breached.
- As a **manager**, I want to see escalations assigned to me with task, stage, assignee, reason, and SLA context, so that I can decide what action is needed.
- As a **manager**, I want to resolve an escalation with a written action note, so that the escalation history shows how it was handled.
- As the **system**, I want automatic SLA breach escalations to resolve the escalation target from the Blueprint override position or the assignee's reporting line, so that routing follows the tenant organization structure.

---

## Acceptance Criteria

### SLA Timer Instances

- [x] `sla_timer_instances` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `task_id`, `stage_instance_id` (nullable), `sub_stage_instance_id` (nullable), `sla_policy_id`, `working_calendar_id`, `started_at`, `deadline_at`, `warning_at`, `paused_at`, `elapsed_before_pause` (integer seconds, default 0), `completed_at`, `status`, `created_at`, `updated_at`
- [x] Exactly one of `stage_instance_id` or `sub_stage_instance_id` is populated per timer.
- [x] `status` uses `SlaTimerStatus` enum: `Running`, `Warning`, `Breached`, `Completed`, `Paused`.
- [x] When `StageInstanceCreated` is received for a stage with `sla_policy_id`, the Tracking module creates a running SLA timer for that stage instance.
- [x] When `SubStageInstanceCreated` is received for a sub-stage with `sla_policy_id`, the Tracking module creates a running SLA timer for that sub-stage instance.
- [x] Timer creation is idempotent: replaying a stage/sub-stage created event does not create duplicate active timers for the same instance.
- [x] `deadline_at` is calculated from `started_at`, the SLA policy value/unit, and the relevant working calendar/public holidays.
- [x] `warning_at` is calculated from `started_at`, `deadline_at`, and `sla_policies.warning_threshold_percentage`.
- [x] If a stage or sub-stage has no SLA policy, no timer is created and the read API returns `sla_health = none` for that instance.

### Timer Completion, Pause, and Resume

- [x] On `StageInstanceCompleted`, the active timer for that stage instance is marked `Completed` with `completed_at = now()`.
- [x] On `SubStageInstanceCompleted`, the active timer for that sub-stage instance is marked `Completed` with `completed_at = now()`.
- [x] On `StageInstanceReturned`, active timers for the returned stage instance and its active sub-stages are marked `Completed` with `completed_at = now()`; the newly created return target stage starts its own timer from its new entry time.
- [x] On `TaskSuspended`, all running/warning timers for the task are marked `Paused`, `paused_at = now()`, and `elapsed_before_pause` is incremented by working seconds elapsed since `started_at` or last resume.
- [x] On `TaskResumed`, paused timers return to `Running` or `Warning` as appropriate, `paused_at` is cleared, and `warning_at`/`deadline_at` are recalculated so the remaining working time is preserved.
- [x] On `TaskCancelled` or `TaskCompleted`, all non-completed timers for the task are marked `Completed` with `completed_at = now()`.
- [x] The Tracking module never updates `tasks`, `task_stage_instances`, `task_sub_stage_instances`, or `task_stage_assignments` tables.

### Warning and Breach Detection

- [x] Scheduled SLA check scans only timers with `status in (Running, Warning)` and due `warning_at` or `deadline_at`.
- [x] When current time reaches `warning_at` and the timer is still running, status changes to `Warning` and `SlaWarningTriggered` event is emitted.
- [x] Warning events are emitted once per timer.
- [x] When current time reaches `deadline_at` and the timer is not completed or paused, status changes to `Breached`, `SlaBreached` event is emitted, and automatic escalation is created.
- [x] Breach events and automatic escalations are created once per timer.
- [x] Paused timers are ignored by warning/breach scans.
- [x] The scheduled check is safe to run concurrently and does not double-warn, double-breach, or double-escalate the same timer.

### Escalations

- [x] `escalations` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `task_id`, `stage_instance_id` (nullable), `sub_stage_instance_id` (nullable), `sla_timer_instance_id` (nullable), `escalation_type`, `escalated_to_user_id`, `escalated_to_position_id` (nullable), `escalated_by_user_id` (nullable), `reason`, `status`, `resolution_note` (nullable), `resolved_at` (nullable), `created_at`, `updated_at`
- [x] `escalation_type` uses `EscalationType` enum: `AutoSlaBreach`, `Manual`.
- [x] `status` uses `EscalationStatus` enum: `Open`, `Resolved`.
- [x] Automatic escalations are created when a timer breaches.
- [x] Automatic escalation target resolution uses the Blueprint stage `escalation_position_id` when present; otherwise it uses the active assignee's current position `reports_to_position_id`.
- [x] If multiple active assignees have different reporting managers, automatic escalation creates one open escalation per unique target manager.
- [x] If no escalation target can be resolved, the breach is still recorded and a module warning is logged with structured context; no fallback to tenant admin is assumed in MVP.
- [x] Manual escalation requires `task.escalate` capability and task visibility under ABAC.
- [x] Manual escalation requires a non-empty `reason`.
- [x] Manual escalation can target an active stage or sub-stage on an active task before or after SLA warning/breach.
- [x] Manual escalation target defaults to the stage/sub-stage escalation target resolution; request may optionally provide an explicit `escalated_to_position_id` if the caller has `task.escalate`.
- [x] Duplicate open manual escalations from the same user to the same target for the same stage/sub-stage are rejected with a domain error.
- [x] Resolving an escalation requires the escalation target user or a user with `task.resolve_escalations` capability within scope.
- [x] Resolution requires `resolution_note`; resolving sets `status = Resolved`, `resolved_at = now()`, and emits `EscalationResolved`.

### APIs

- [x] `GET /api/v1/tracking/sla/tasks/{task}` — returns current SLA health for the task's active stage/sub-stage timers. ABAC task visibility enforced.
- [x] `GET /api/v1/tracking/sla/timers` — cursor-paginated list of SLA timers filtered by `status`, `health`, `task_id`, `blueprint_id`, `stage_id`, `assigned_user_id`, `department_id`, `deadline_from`, `deadline_to`. Requires follow-up visibility or own participation scope.
- [x] `GET /api/v1/tracking/escalations` — cursor-paginated list of escalations filtered by `status`, `type`, `assigned_to_me`, `task_id`, `blueprint_id`, `department_id`, `created_from`, `created_to`. ABAC scope enforced.
- [x] `GET /api/v1/tracking/escalations/{escalation}` — show escalation detail with task, stage/sub-stage, timer, assignee, target manager, reason, and resolution fields. ABAC scope enforced.
- [x] `POST /api/v1/tracking/escalations` — create manual escalation for a stage or sub-stage. Requires `task.escalate`, task visibility, and `reason`.
- [x] `POST /api/v1/tracking/escalations/{escalation}/resolve` — resolve an open escalation with `resolution_note`. Requires target manager relationship or `task.resolve_escalations`.
- [x] All responses use API Resources and expose `public_id` only; internal `id` is never returned.
- [x] All list endpoints use cursor pagination and return `{data, next_cursor, has_more}`.

### Domain Events

- [x] New Tracking events implement `ShouldDispatchAfterCommit`: `SlaTimerStarted`, `SlaTimerPaused`, `SlaTimerResumed`, `SlaTimerCompleted`, `SlaWarningTriggered`, `SlaBreached`, `EscalationCreated`, `EscalationResolved`.
- [x] Event payloads include tenant context, task public ID, stage/sub-stage public ID where available, timer public ID, escalation public ID where applicable, and recipient user public IDs where applicable.
- [x] Task module events consumed by this spec are handled idempotently and may be safely retried.

### General

- [x] All Tracking data lives in the tenant DB; no `tenant_id` columns are added.
- [x] Redis/cache keys, if used, are tenant-prefixed.
- [x] All mutating endpoints and scheduled/queued writes emit structured logs through the `tracking` logging channel.
- [x] Feature tests cover: timer creation, no-SLA stage behavior, warning threshold, breach threshold, auto escalation routing, manual escalation, resolution, pause/resume with working calendar, cancellation/completion cleanup, ABAC denial, cursor pagination, and duplicate prevention.

---

## Non-Functional Requirements

### Pagination

- `GET /api/v1/tracking/sla/timers` uses **cursor pagination** because timer history can exceed 1000 rows per tenant. See `coding-standards.md` — Pagination Strategy.
- `GET /api/v1/tracking/escalations` uses **cursor pagination** because escalation history can exceed 1000 rows per tenant.
- `GET /api/v1/tracking/sla/tasks/{task}` returns a bounded full object scoped to one task and does not paginate.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- SLA timer list results are **not cached**; they are time-sensitive, cursor-paginated, and change via scheduler.
- Escalation list results are **not cached**; open/resolved status must be current.
- Working calendar holiday lookups may reuse or create a tenant-prefixed cold cache: `{tenant_slug}:organization:holidays:{calendar_public_id}:{year}` with TTL 3600s. Invalidated on public holiday create/update/delete events.
- Optional per-task SLA health cache may use `{tenant_slug}:tracking:sla_health:task:{task_public_id}` with TTL 60s only if implementation needs it; invalidated on timer started/paused/resumed/completed/warning/breached events.
- All cache behavior must follow `coding-standards.md` — Caching, including tenant-prefixed keys and event-driven invalidation.

### Rate Limiting

- SLA timer list and escalation list/show endpoints: `RateLimits::LIST` (60/min per user).
- Manual escalation and escalation resolution endpoints: `RateLimits::MUTATE` (30/min per user).
- No route-level throttle strings; controllers must use `HasRateLimiting` and `RateLimits` constants per `coding-standards.md`.

### Database Transactions

- Timer creation from stage/sub-stage entry events uses `DB::transaction()` when it creates the timer and emits `SlaTimerStarted`.
- Pause/resume operations use `DB::transaction()` because multiple timer rows for the same task may be updated.
- Warning transition uses `DB::transaction()` for status update plus `SlaWarningTriggered` event.
- Breach transition uses `DB::transaction()` for timer status update plus automatic escalation creation plus events.
- Manual escalation creation uses `DB::transaction()` for duplicate check plus escalation insert plus event.
- Escalation resolution uses `DB::transaction()` for status update plus event.
- Single read endpoints do not use transactions.
- All transaction boundaries must follow `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `tracking`.
- All service methods and scheduled/queued handlers use try/catch with `Log::channel('tracking')`.
- Structured log context must include: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by` (`system` for scheduled checks), plus `task_id` and timer/escalation public IDs when available.
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages.
- Expected domain exceptions include: `SlaPolicyMissingException`, `SlaTimerAlreadyExistsException`, `SlaTimerNotActiveException`, `EscalationTargetNotFoundException`, `DuplicateOpenEscalationException`, `EscalationAlreadyResolvedException`, `EscalationResolutionUnauthorizedException`.
- Error handling must follow `coding-standards.md` — Error Handling & Logging.

### Enums

- Create `SlaTimerStatus` enum in `app/Modules/Tracking/Enums/SlaTimerStatus.php`: `Running = 1`, `Warning = 2`, `Breached = 3`, `Completed = 4`, `Paused = 5`.
- Create `EscalationType` enum in `app/Modules/Tracking/Enums/EscalationType.php`: `AutoSlaBreach = 1`, `Manual = 2`.
- Create `EscalationStatus` enum in `app/Modules/Tracking/Enums/EscalationStatus.php`: `Open = 1`, `Resolved = 2`.
- Reuse `SlaUnit` from `app/Modules/Blueprint/Enums/SlaUnit.php`; do not duplicate SLA unit values.
- Form Requests use `Rule::enum(...)`; services use enum cases and never raw integers.
- Enum usage must follow `coding-standards.md` — Enum Usage.

### Queue Jobs

- SLA threshold scanning runs from the Laravel scheduler and dispatches tenant-scoped queue jobs for due timer batches.
- `CheckSlaTimersJob` (or equivalent) must implement `ShouldQueue`, include tenant context, use `tries = 3`, and `backoff = [30, 60, 120]`.
- Notification delivery jobs are out of scope for this spec; this spec only emits domain events for Spec 008.
- Domain events implement `ShouldDispatchAfterCommit`.
- Queue behavior must follow `coding-standards.md` — Queues & Jobs, including tenant context in payloads.

---

## Out of Scope

- Notification persistence and delivery (in-app/email/SMS/WhatsApp) — Spec 008.
- Follow-up board aggregation and manual follow-up action logs — Spec 010.
- Analytics reports for SLA compliance and escalation trends — Spec 009.
- Audit event persistence — Spec 015 consumes this spec's events.
- Stage-level external block/pause and resume — V2; MVP only pauses timers through task-level suspension.
- Chain escalation beyond first-level manager — V2.
- Configurable escalation rules beyond Blueprint `escalation_position_id` and reporting-line fallback — V2.
- Editing SLA policies — already provided by Spec 004.
- Modifying Task, Stage, Sub-stage, or Assignment tables from Tracking services.
- Reassigning stages as part of escalation resolution — use Spec 006 assignment override endpoint; this spec records the resolution note only.
- Extending task deadlines from escalation resolution — deferred until an approved deadline-extension workflow exists.

---

## Open Questions (Answered)

- [x] `sla_timer_instances.public_id`: the ERD omits `public_id`, but API rules require public IDs for exposed resources. **Decision: add `public_id` to timer instances.** Implemented as UUID v7 column.
- [x] Working calendar selection: should timer calculation use the task initiator's department calendar, the stage owning department calendar, or tenant default? **Decision: use stage/sub-stage `owning_department_id` calendar when available; fall back to tenant default working calendar.** Implementation uses tenant default only (department lookup deferred — see plan.md).
- [x] Explicit manual escalation target: should callers be allowed to choose `escalated_to_position_id`, or should all manual escalations use automatic target resolution? **Decision: allow optional explicit target for users with `task.escalate`, otherwise automatic resolution.** Implemented in `SlaEscalationService::createManualEscalation()`.
- [x] New capabilities: confirm `task.escalate` and `task.resolve_escalations` should be added to `CapabilitySeeder`, scoped by department/monitoring scope. **Decision: yes — added to `CapabilitySeeder`.**
- [x] Timer completion on stage return: should returned timers be marked `Completed` or a distinct terminal status such as `Cancelled`? **Decision: use `Completed` for MVP.** Implemented — no separate `Cancelled` enum case.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
