# Spec: Follow-Up Board & Tracking API

> **Number:** 010
> **Date:** 2026-06-14
> **Status:** `completed`
> **Milestone:** M6 — Analytics, Follow-up & Search
> **Depends on:** `005-task-execution` (tasks, priorities, stage instances, assignments, `TaskVisibilityScope`), `006-stage-lifecycle` (stage progression, completion, returns, timeline), `007-sla-escalation` (SLA timers, escalations, breach/warning status, `SlaTimerStatus` enum), `003-iam-abac` (users, ABAC policy engine, monitoring scope grants, capabilities), `002-organization-structure` (departments, positions), `009-analytics-reporting` (read-only analytics patterns, `IntersectsTaskVisibility` trait)
> **Provides APIs:** follow-up board (unified task list with SLA health), follow-up board filters, follow-up action log (manual follow-up entry), follow-up action list, sort options, overdue/at-risk task lists, time-at-stage per task
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/006-follow-up-center`
> **Author:** Momentum init
> **Branch:** `feat/010-follow-up-board`
> **Base branch:** `main`

---

## Problem

Specs 005–009 delivered task execution, stage lifecycle, SLA enforcement, notifications, and analytics dashboards. But the platform still lacks the operational follow-up layer that replaces the manual phone calls, spreadsheets, and WhatsApp coordination that government follow-up specialists (*متابعة*) rely on today.

Currently, a follow-up specialist or department director who needs to answer "which tasks are stuck, who has the ball, and what has already been tried?" must:

1. Open the task list and mentally filter by SLA health, stage, assignee, and department.
2. Cross-reference the SLA timer API to determine which tasks are overdue or at risk.
3. Have no system of record for follow-up actions — phone calls, messages, and verbal reminders are lost.
4. Have no way to see time elapsed at the current stage at a glance on the board.
5. Have no way to filter by external reference number to find all tasks linked to a correspondence or contract.

The analytics module (Spec 009) delivers *aggregated* read-only dashboards. This spec delivers the *operational* follow-up board — a live, filterable, sortable task list enriched with SLA health, time-at-stage, current assignees, and a manual follow-up action log. It is the primary workspace for anyone whose job is to chase work through the organization.

---

## Goal

Deliver a Follow-Up Board API that exposes a unified, filterable, sortable task list with real-time SLA health indicators, current stage/assignee information, and time-at-stage calculations. Add a follow-up action log so users can record and retrieve manual follow-up actions (phone calls, messages, reminders) against tasks. All endpoints enforce ABAC visibility via the existing `TaskVisibilityScope` and respect confidentiality rules. The follow-up board is read-only with respect to task execution state — it never mutates task, stage, SLA, or escalation records (except for creating/reading follow-up action log entries in its own table).

---

## User Stories

### Follow-Up Board

- As a **follow-up specialist**, I want a unified board showing every active task in my monitoring scope with current stage, active assignees, priority, and SLA health, so that I can see the full operational picture on one screen.
- As a **department director**, I want to filter the board by tasks whose current active stage is owned by my department, so that I can focus on work flowing through my directorate.
- As a **follow-up specialist**, I want to filter by task status (active, suspended, overdue, at-risk, completed, cancelled), so that I can narrow down to problem areas.
- As a **follow-up specialist**, I want to filter by current stage type (Action, Review, Approval, Decision, Information Gathering), so that I can see tasks stuck at specific workflow phases.
- As a **follow-up specialist**, I want to filter by current assignee, so that I can see every task currently assigned to a specific person.
- As a **follow-up specialist**, I want to filter by priority (Routine, Urgent, Critical), so that I can focus on high-priority work first.
- As a **follow-up specialist**, I want to filter by Blueprint category, so that I can see tasks of a specific workflow type.
- As a **follow-up specialist**, I want to filter by date range (created, due, or completed within a period), so that I can scope my view to a relevant timeframe.
- As a **follow-up specialist**, I want to filter by external reference number, so that I can find all tasks linked to a specific correspondence, contract, or other external identifier.
- As a **follow-up specialist**, I want to see the current assignees per task ("who has the ball right now"), so that I know who to contact.
- As a **follow-up specialist**, I want to see time elapsed at the current stage in working hours/days, so that I can prioritize by stalling duration.
- As a **follow-up specialist**, I want an SLA health indicator per task (Green, Amber, Red, Grey), so that I can instantly assess urgency.
- As a **follow-up specialist**, I want to sort the board by deadline, priority, department, stage type, or time-at-stage, so that I can organize work by what matters most.
- As a **follow-up specialist**, I want a dedicated overdue task list (stage SLA breached), sorted by days overdue, so that I can prioritize the most critical items.
- As a **follow-up specialist**, I want a dedicated at-risk task list (stage SLA in warning), so that I can act before breach.
- As a **follow-up specialist**, I want a stage-level bottleneck indicator showing which stage type in which department holds the most overdue/at-risk tasks, so that I can target systemic problems.

### Follow-Up Action Log

- As a **follow-up specialist**, I want to log a manual follow-up action (phone call, message, meeting, other) against a task, so that the next person knows what was already tried.
- As a **follow-up specialist**, I want to view all follow-up actions logged against a task in chronological order, so that I have a complete follow-up history.
- As a **department director**, I want to see follow-up actions logged by my team, so that I can verify follow-up effort.

### System

- As the **system**, I want all board endpoints to enforce the same ABAC visibility and confidentiality rules as task execution (`TaskVisibilityScope`), so that sensitive tasks are never leaked through the follow-up board.
- As the **system**, I want follow-up board queries to exclude `draft` tasks, so that only launched work appears.
- As the **system**, I want follow-up action log entries to be append-only (no edit, no delete), so that follow-up history is reliable.

---

## Acceptance Criteria

### Follow-Up Board

- [x] `GET /api/v1/follow-up/board` — returns a cursor-paginated list of non-draft tasks visible to the caller, enriched with: `public_id`, `title_ar`, `title_en`, `status`, `priority`, `classification_level`, `current_stage` (name, type, public_id), `current_assignees` (name, position, public_id per assignee), `sla_health` (Green/Amber/Red/Grey), `time_at_current_stage_seconds` (working seconds), `department` (owning department of active stage), `blueprint_category`, `due_date`, `created_at`, `launched_at`.
- [x] Board excludes `draft` tasks (`status != draft`) and `archived` tasks (`archived_at IS NULL`).
- [x] Board enforces `TaskVisibilityScope` — users only see tasks matching their ABAC visibility (org-wide, department-touched, follow-up scope, own participation).
- [x] Board enforces confidentiality rules — confidential tasks visible only to named participants, governance participants, and override-capable users.
- [x] SLA health is derived from the active stage/sub-stage `SlaTimerInstance` status: `Running` → Green, `Warning` → Amber, `Breached` → Red, `Paused` (suspended task) → Grey. If no active timer exists, health is Green.
- [x] Time-at-stage is calculated as working seconds from `task_stage_instances.entered_at` to now, using the tenant's working calendar.

### Filters

- [x] `status` filter: `active`, `suspended`, `overdue`, `at_risk`, `completed`, `cancelled`. `overdue` and `at_risk` are virtual statuses that cross-reference SLA timer status.
- [x] `stage_type` filter: accepts a `stage_type_public_id` and filters to tasks whose current active stage matches that type.
- [x] `assignee` filter: accepts a `user_public_id` and filters to tasks whose current active stage/sub-stage assignments include that user.
- [x] `department` filter: accepts a `department_public_id` and filters to tasks whose current active stage `owning_department_id` matches.
- [x] `priority` filter: accepts one or more `priority_public_id` values.
- [x] `blueprint_category` filter: accepts a `blueprint_category_public_id`.
- [x] `date_from` / `date_to` filters based on `created_at` by default, with optional `date_field` parameter to choose `created_at`, `due_date`, or `completed_at`.
- [x] `external_reference` filter: accepts a reference number string and filters tasks that have a matching `task_external_references.reference_number` (exact or partial match). *(Note: depends on 014-external-references being implemented; if not yet available, this filter returns 422 with a clear message.)*
- [x] `search` filter: accepts a text string and filters by `title_ar` or `title_en` partial match (ILIKE).
- [x] Invalid filter combinations return 422 with clear validation messages.

### Sort

- [x] `sort_by` parameter accepts: `priority`, `due_date`, `created_at`, `time_at_stage`, `department`, `stage_type`. Default: `time_at_stage` descending (longest-waiting first).
- [x] `sort_direction` parameter accepts: `asc`, `desc`. Default: `desc`.

### Overdue & At-Risk Lists

- [x] `GET /api/v1/follow-up/overdue` — cursor-paginated list of tasks with an active SLA timer in `Breached` status, sorted by breach duration descending. Same enrichment and ABAC filtering as the board.
- [x] `GET /api/v1/follow-up/at-risk` — cursor-paginated list of tasks with an active SLA timer in `Warning` status, sorted by time remaining ascending. Same enrichment and ABAC filtering as the board.

### Bottleneck Indicator

- [x] `GET /api/v1/follow-up/bottlenecks` — returns a bounded list (top 10) of stage type + department combinations ranked by `overdue_count × 2 + at_risk_count`, with `stage_type`, `department`, `overdue_count`, `at_risk_count`, `avg_time_at_stage_seconds`. Requires `task.view.organization` or `task.view.follow_up_scope` capability.

### Follow-Up Action Log

- [x] `follow_up_actions` table in tenant DB: `id`, `public_id` (UUID v7, unique), `task_id` (FK), `user_id` (FK — the user who logged the action), `action_type` (enum: PhoneCall, Message, Meeting, Email, Other), `note_ar` (required), `note_en` (optional), `contact_name` (optional — who was contacted), `created_at`, `updated_at`.
- [x] `FollowUpAction` model extends `TenantModel`, uses `HasPublicId`, route model binding by `public_id`.
- [x] `POST /api/v1/follow-up/tasks/{task}/actions` — creates a follow-up action log entry. Requires the caller to have visibility on the task via `TaskVisibilityScope`. Requires `task.view.follow_up_scope` or `task.view.organization` or `task.view.department_touched` capability. Returns the created action.
- [x] `GET /api/v1/follow-up/tasks/{task}/actions` — returns all follow-up actions for a task in chronological order (oldest first). Cursor-paginated. Requires task visibility.
- [x] Follow-up action log entries are append-only — no PUT, PATCH, or DELETE endpoints.
- [x] All API responses expose `public_id` only — never internal `id`.

### Response Shape

- [x] Board, overdue, and at-risk endpoints return cursor-paginated `{data, next_cursor, has_more}`.
- [x] Bottleneck endpoint returns bounded `{data: [...]}` (no pagination, max 10 items).
- [x] Follow-up action list returns cursor-paginated `{data, next_cursor, has_more}`.
- [x] Single follow-up action creation returns the action resource (no wrapping due to `withoutWrapping`).

### Tests

- [x] Feature tests cover: board list for a follow-up specialist with `task.view.follow_up_scope`, board list for an org-wide viewer with `task.view.organization`, each filter in isolation, sort by time-at-stage, sort by priority, overdue list, at-risk list, bottleneck endpoint, follow-up action creation, follow-up action listing, ABAC denial when capability is missing, confidentiality filtering, draft task exclusion, and cursor pagination.

---

## Non-Functional Requirements

### Pagination

- Board, overdue, at-risk, and follow-up action list endpoints use **cursor pagination** (tasks and actions can exceed 1000 rows per tenant). See `coding-standards.md` — Pagination Strategy.
- Bottleneck endpoint returns bounded scalar results (max 10) and does not paginate.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- Follow-up board endpoints are **not cached** because they show real-time SLA health, time-at-stage, and current assignees — stale data would be misleading for operational follow-up.
- Bottleneck indicator may be cached at `{tenant_slug}:followup:bottlenecks` with TTL 300s (warm tier), invalidated on stage completion, stage advance, SLA warning, and SLA breach events.
- All cache keys must be tenant-prefixed and invalidated by domain events, not TTL alone. See `coding-standards.md` — Caching.

### Rate Limiting

- Board, overdue, at-risk, bottleneck, and action list endpoints: `RateLimits::LIST` (60/min per user).
- Follow-up action creation: `RateLimits::MUTATE` (30/min per user).
- No route-level throttle strings; controllers use the `HasRateLimiting` trait and `RateLimits` constants per `coding-standards.md` — Rate Limiting.

### Database Transactions

- Follow-up action creation is a single write — no `DB::transaction()` required.
- All board endpoints are read-only — no `DB::transaction()` required.
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `followup` (add to `config/logging.php` following the existing module channel pattern).
- All service methods use try/catch with `Log::channel('followup')`.
- Structured log context includes: `tenant_slug`, `action` (e.g., `followup.board`, `followup.action.create`), `entity_type`, `entity_id`, `performed_by`.
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages.
- Expected domain exceptions: `FollowUpActionNotAllowedException` (if user lacks capability to log actions), `InvalidBoardFilterException` (if filter values are invalid).
- Error handling must follow `coding-standards.md` — Error Handling & Logging.

### Enums

- Create `FollowUpActionType` enum in `app/Modules/FollowUp/Enums/FollowUpActionType.php`: `PhoneCall = 1`, `Message = 2`, `Meeting = 3`, `Email = 4`, `Other = 5`.
- Create `SlaHealth` enum in `app/Modules/FollowUp/Enums/SlaHealth.php`: `Green = 1`, `Amber = 2`, `Red = 3`, `Grey = 4` (distinct from Analytics `TaskHealth` — follow-up uses it for per-task SLA status display; may share values but lives in the FollowUp module namespace).
- Create `BoardSortField` enum in `app/Modules/FollowUp/Enums/BoardSortField.php`: string-backed enum with `Priority = 'priority'`, `DueDate = 'due_date'`, `CreatedAt = 'created_at'`, `TimeAtStage = 'time_at_stage'`, `Department = 'department'`, `StageType = 'stage_type'`.
- Create `BoardSortDirection` enum in `app/Modules/FollowUp/Enums/BoardSortDirection.php`: string-backed enum with `Asc = 'asc'`, `Desc = 'desc'`.
- Reuse existing `TaskStatus`, `StageInstanceStatus`, `SlaTimerStatus`, `ClassificationLevel` enums from Task and Tracking modules — do not duplicate.
- Form Requests use `Rule::enum(...)` for `action_type`, `sort_by`, and `sort_direction`. Services use enum cases, never raw integers. See `coding-standards.md` — Enum Usage.

### Queue Jobs

- All follow-up endpoints are synchronous queries in MVP.
- Domain events emitted on follow-up action creation implement `ShouldDispatchAfterCommit`.
- Bottleneck cache invalidation listeners may consume existing Task and Tracking events — these listeners are synchronous.
- Queue behavior must follow `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **Follow-up action history per task** (feature #114) — V2. MVP provides the action list; an enhanced timeline view with filtering and aggregation is deferred.
- **Pin critical tasks to personal watch list** (feature #115) — V2. Requires a new `user_watchlist` table and personal workspace integration.
- **Save board filter configuration** (feature #116) — V2. Requires a `saved_filters` table and user-preference management.
- **Bulk status view** (feature #117) — V2. Multi-select with combined SLA summary is a frontend/API extension.
- **External reference filter** — functional only after `014-external-references` is implemented. If the `task_external_references` table does not exist, the `external_reference` filter returns 422.
- **Real-time websocket push of board updates** — MVP uses polling; push is deferred.
- **Modifying Task, Tracking, IAM, or Organization tables** from FollowUp services (except the new `follow_up_actions` table).
- **Editing or deleting follow-up action log entries** — entries are append-only.
- **Search module integration** — `011-search-discovery` will provide full-text search; the board provides only a basic `title` ILIKE filter.

---

## Open Questions (Answered)

- [x] **Should `FollowUpActionType` enum be tenant-configurable?** **Decision: No in MVP.** Use the fixed 5-case enum (`PhoneCall`, `Message`, `Meeting`, `Email`, `Other`). Tenant-configurable action types are a V2 extension via a lookup table.
- [x] **Should the `external_reference` filter do exact match or partial (ILIKE) match?** **Decision: Exact match.** Reference numbers are structured identifiers; partial match deferred to V2 / Search module (Spec 011). Until Spec 014 is implemented, the filter returns 422.
- [x] **Should the bottleneck endpoint reuse the same scoring formula as Analytics (Spec 009)?** **Decision: Yes.** `score = overdue_count × 2 + at_risk_count`, sorted descending, with average time-at-stage as tie-breaker.
- [x] **Should follow-up action creation emit a domain event for Audit consumption?** **Decision: Yes.** Emit `FollowUpActionCreated` implementing `ShouldDispatchAfterCommit`; Spec 015 (Audit Trail) will consume it when implemented.
- [x] **Where does the FollowUp module live — as a new bounded context or inside the Task module?** **Decision: New bounded context at `app/Modules/FollowUp/`.** The board reads from Task and Tracking but owns `follow_up_actions`, enums, services, and controllers, mirroring the Analytics module pattern.
- [x] **Should time-at-stage calculation exclude non-working time?** **Decision: Yes.** Use `WorkingDayCalculator::workingSecondsBetween()` with the tenant's default working calendar, consistent with SLA timers (Spec 007).

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
