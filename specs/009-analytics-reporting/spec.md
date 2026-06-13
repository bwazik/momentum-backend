# Spec: Analytics & Reporting

> **Number:** 009
> **Date:** 2026-06-13
> **Status:** `completed`
> **Milestone:** M6 — Analytics, Follow-up & Search
> **Depends on:** `002-organization-structure` (departments, positions, authority grades, working calendars), `003-iam-abac` (users, ABAC policy engine, capability grants), `004-blueprint-engine` (blueprints, categories, stages), `005-task-execution` (tasks, priorities, stage instances, assignments, task lifecycle), `006-stage-lifecycle` (stage progression, completion, returns, timeline), `007-sla-escalation` (SLA timers, escalations, breach/warning status)
> **Provides APIs:** executive dashboard summary, department performance view, director/manager dashboard, stage bottleneck view, task aging report, red/amber/green department health, drill-down task lists for every metric
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/001-executive-dashboard`, `../frontend/specs/008-analytics-reporting`, `../frontend/specs/011-department-manager-dashboard`
> **Author:** Momentum init
> **Branch:** `feat/009-analytics-reporting`
> **Base branch:** `main`

---

## Problem

Specs 005, 006, and 007 made tasks executable and time-accountable, but leadership still lacks a single source of truth for organizational throughput. Today the platform stores every stage, assignment, SLA timer, and escalation, yet there is no read-only analytics layer that surfaces:

- how many tasks are active, overdue, at-risk, suspended, or completed across the tenant
- which departments are moving work on time and which are chronically late
- which stage types and departments form the biggest bottlenecks
- how long individual tasks have been waiting at their current stage
- a color-coded health snapshot that an undersecretary or director can read in seconds

Without this spec, executives and follow-up specialists must manually reconstruct operational health from task lists and SLA timers. The analytics module turns the raw execution data produced by Task and Tracking into actionable, read-only dashboards and reports without ever writing to domain tables.

---

## Goal

Deliver the Analytics module: a read-only reporting layer that consumes existing Task, Tracking, and Organization data to produce executive, department-level, and manager dashboards. The module exposes aggregated summary APIs plus drill-down task lists for every metric, enforcing ABAC visibility so each caller only sees data they are authorized to see.

Analytics never writes to Task, Tracking, IAM, or Organization tables. It may use read-optimized query strategies (indexed views or materialized read models if needed) but remains within the tenant DB. PDF export, scheduled digests, predictive risk, and per-individual performance reports are explicitly out of scope for MVP.

---

## User Stories

### Executive Dashboard

- As a **minister or top executive**, I want a high-level summary of total active tasks, overdue count, at-risk count, suspended count, and completion rate, so that I can see the organization's operational pulse at a glance.
- As an **executive**, I want a stage-level bottleneck view showing which stage type in which department causes the most delays, so that I can direct corrective action where it matters.
- As an **executive**, I want red/amber/green health indicators per department, so that I can instantly identify underperforming directorates.
- As an **executive**, I want to click any summary metric and see the underlying task list, so that I can investigate without losing context.

### Department & Manager Dashboard

- As a **department director or manager**, I want a department-specific dashboard with completion rates, average stage delay, and active task count for my directorate, so that I can manage team throughput.
- As a **manager**, I want to see active stage and sub-stage assignments per employee on my team, so that I can balance workload and identify blockers.
- As a **manager**, I want to see pending actions, overdue items, and at-risk items grouped by team member, so that I can run effective follow-up meetings.

### Follow-Up & Aging

- As a **follow-up specialist**, I want a task aging report listing all open tasks sorted by how long they have waited at their current stage, so that I can prioritize manual follow-up.
- As a **follow-up specialist**, I want to filter every report by status, priority, department, Blueprint category, and date range, so that I can narrow the view to the work I am monitoring.
- As a **follow-up specialist**, I want every report to respect my monitoring scope grants, so that I only see data for the departments or Blueprint categories I am responsible for.

### System

- As the **system**, I want all analytics endpoints to be read-only and not mutate task, stage, SLA, or escalation data, so that reporting queries cannot corrupt execution state.
- As the **system**, I want analytics queries to enforce the same ABAC and confidentiality rules as task execution, so that sensitive tasks are never leaked through reporting APIs.

---

## Acceptance Criteria

### Read-Only Architecture

- [x] Analytics module lives under `app/Modules/Analytics/` and only queries existing Task, Tracking, Organization, and IAM tables.
- [x] Analytics services never call `create`, `update`, `delete`, or any other mutating method on Task, Tracking, IAM, or Organization models.
- [x] Analytics data stays in the tenant DB; no `tenant_id` columns are added to any table.
- [x] All analytics query results are filtered by the caller's ABAC visibility (`TaskVisibilityScope` or equivalent) and confidentiality rules.

### Dashboard APIs

- [x] `GET /api/v1/analytics/executive/summary` — returns organization-wide counts: `active`, `overdue`, `at_risk`, `suspended`, `completed`, `cancelled`, `completion_rate`. ~~Prior-period comparison is deferred to V2.~~ Requires `analytics.view.organization` capability.
- [x] `GET /api/v1/analytics/executive/bottlenecks` — returns top bottlenecking stage types by department: stage type, department, count of overdue + at-risk tasks, average time-at-stage. Requires `analytics.view.organization` capability.
- [x] `GET /api/v1/analytics/executive/department-health` — returns red/amber/green health status per department with supporting counts. Requires `analytics.view.organization` capability.
- [x] `GET /api/v1/analytics/departments/{department}/performance` — returns department-specific metrics: active tasks, completion rate, average stage delay, overdue count, at-risk count. Requires `analytics.view.department`, `analytics.view.individuals_in_department`, or `analytics.view.organization` capability within scope.
- [x] `GET /api/v1/analytics/departments/{department}/team` — returns per-employee metrics inside the department: active stage/sub-stage assignments, overdue assignments, at-risk assignments, completed stages. Requires `analytics.view.department`, `analytics.view.individuals_in_department`, or `analytics.view.organization` capability within scope.
- [x] `GET /api/v1/analytics/tasks/aging` — returns task aging report: all open tasks sorted by time at current stage, with task public_id, title, current stage/sub-stage, active assignees, SLA health, priority, and created_at. Supports filters: `status`, `priority`, `department_id`, `blueprint_category_id`, `date_from`, `date_to`. Requires appropriate analytics or follow-up visibility.

### Drill-Down APIs

- [x] `GET /api/v1/analytics/executive/summary/drill-down` — returns a cursor-paginated task list matching the requested metric (`active`, `overdue`, `at_risk`, `suspended`, `completed`, `cancelled`) and optional filters. Requires `analytics.view.organization` capability.
- [x] `GET /api/v1/analytics/executive/bottlenecks/{stage_type}/drill-down` — returns cursor-paginated tasks currently at the specified bottleneck stage type, filtered by department and health. Requires `analytics.view.organization` capability.
- [x] `GET /api/v1/analytics/departments/{department}/performance/drill-down` — returns cursor-paginated tasks contributing to the department performance metric. Requires `analytics.view.department` capability within scope.
- [x] Every drill-down endpoint reuses the same ABAC visibility scope as the parent summary endpoint.

### Filtering & Date Ranges

- [x] All list/report endpoints support `date_from` and `date_to` filters based on task `created_at`, `completed_at`, or `due_date` depending on the report context.
- [x] All list/report endpoints support `priority`, `status`, `department_id`, and `blueprint_category_id` filters where applicable.
- [x] Invalid filter combinations return a 422 validation error with clear messages.

### Data Definitions

- [x] `draft` tasks (`status = draft`) are **excluded** from all analytics reports and dashboards; analytics reflects launched work only.
- [x] `active` tasks are tasks with `status = active` and `archived_at IS NULL` and `deleted_at IS NULL`.
- [x] `suspended` tasks are tasks with `status = suspended` and `archived_at IS NULL` and `deleted_at IS NULL`.
- [x] `completed` tasks are tasks with `status = completed` and `archived_at IS NULL` and `deleted_at IS NULL` within the selected date range.
- [x] `cancelled` tasks are tasks with `status = cancelled` and `archived_at IS NULL` and `deleted_at IS NULL` within the selected date range.
- [x] `archived` tasks (`archived_at IS NOT NULL`) are included only in archive-specific reports (out of scope for this spec; see Domain 13 / Spec 015) and excluded from operational dashboards.
- [x] `overdue` tasks are tasks whose active stage or sub-stage SLA timer status is `Breached`.
- [x] `at_risk` tasks are tasks whose active stage or sub-stage SLA timer status is `Warning`.
- [x] `completion_rate` is calculated as `completed / (completed + cancelled + active + suspended)` over the selected period, or a similarly defined metric documented in the API contract.
- [x] `average stage delay` is the average working seconds between stage entry (`task_stage_instances.entered_at`) and stage completion (`task_stage_instances.exited_at` where `status = completed`) for completed stage instances in the scope.
- [x] Department attribution for a task uses `owning_department_id` on the active `task_stage_instances` or `task_sub_stage_instances` row; for aggregated department reports, a task contributes to the department of its current active stage/sub-stage.

### Response Shape

- [x] All summary endpoints return transformed JSON via API Resources; no internal `id` is exposed.
- [x] All drill-down/list endpoints use cursor pagination and return `{data, next_cursor, has_more}`.
- [x] Department and employee references use `public_id` only.

### Tests

- [x] Feature tests cover: executive summary for a tenant admin with `analytics.view.organization`, department performance for a manager with `analytics.view.department`, team view for a manager with `analytics.view.individuals_in_department`, task aging report, drill-down for each summary metric, ABAC denial when capability is missing, confidentiality filtering (confidential tasks excluded from users without relationship), tenant isolation, and cursor pagination.

---

## Non-Functional Requirements

### Pagination

- All drill-down and task-list endpoints use **cursor pagination** because task history can exceed 1000 rows per tenant. See `coding-standards.md` — Pagination Strategy.
- Summary endpoints return bounded scalar objects and do not paginate.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- Executive summary and department health results may be cached at `{tenant_slug}:analytics:executive_summary` with TTL 300s (warm tier), invalidated on task lifecycle events (created, launched, suspended, resumed, cancelled, completed) and stage/sub-stage completion/return events.
- Department performance and team views may be cached at `{tenant_slug}:analytics:department:{department_public_id}:{metric}` with TTL 300s, invalidated on the same task and stage lifecycle events.
- Bottleneck and aging reports are **not cached** because they are time-sensitive and change as SLA timers progress.
- All cache keys must be tenant-prefixed and invalidated by domain events, not TTL alone. See `coding-standards.md` — Caching.

### Rate Limiting

- Summary and report endpoints: `RateLimits::LIST` (60/min per user).
- Drill-down list endpoints: `RateLimits::LIST` (60/min per user).
- No route-level throttle strings; controllers use the `HasRateLimiting` trait and `RateLimits` constants per `coding-standards.md` — Rate Limiting.

### Database Transactions

- Analytics endpoints are read-only; no `DB::transaction()` is required for queries.
- If analytics later materializes read models or cache-warming jobs, those writes must use `DB::transaction()` where multiple rows are updated. See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `analytics` (add to `config/logging.php` following the existing module channel pattern).
- All service methods use try/catch with `Log::channel('analytics')`.
- Structured log context includes: `tenant_slug`, `action` (e.g. `analytics.executive_summary`, `analytics.department_performance`), `entity_type`, `entity_id` (report or department public_id), `performed_by` (caller public_id), plus report filters when applicable.
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages.
- Expected domain exceptions: `AnalyticsScopeDeniedException`, `InvalidReportFilterException`.
- Error handling must follow `coding-standards.md` — Error Handling & Logging.

### Enums

- Create `TaskHealth` enum in `app/Modules/Analytics/Enums/TaskHealth.php`: `Green = 1`, `Amber = 2`, `Red = 3`, `Grey = 4`.
- Create `DepartmentHealth` enum in `app/Modules/Analytics/Enums/DepartmentHealth.php`: `Green = 1`, `Amber = 2`, `Red = 3`.
- Reuse existing `TaskStatus`, `StageInstanceStatus`, `SlaTimerStatus`, and `ClassificationLevel` enums from Task and Tracking modules; do not duplicate their values.
- Form Requests use `Rule::enum(...)`; services use enum cases, never raw integers. See `coding-standards.md` — Enum Usage.

### Queue Jobs

- Analytics endpoints are synchronous read queries in MVP.
- Cache warming after significant task lifecycle events may be dispatched to a queue via `ShouldQueue` jobs with `public int $tries = 3` and `public array $backoff = [30, 60, 120]`.
- Domain events consumed for cache invalidation implement `ShouldDispatchAfterCommit`.
- Queue behavior must follow `coding-standards.md` — Queues & Jobs, including tenant context in payloads.

---

## Out of Scope

- PDF export of dashboards (feature #165) — V2.
- Scheduled weekly performance summary emails (feature #166) — V2.
- Blueprint performance report with average time per stage (feature #160) — V2.
- Stage form analytics (feature #161) — V2; depends on structured stage forms not in MVP.
- Individual performance view with per-employee completion/overdue averages (feature #162) — V2.
- Stage SLA compliance percentage report (feature #163) — V2.
- Period comparison (this month vs last month vs same period last year) (feature #164) — V2.
- Task volume by Blueprint type over time (feature #167) — V2.
- Predictive SLA risk (feature #168) — V3.
- Modifying Task, Tracking, IAM, or Organization tables from Analytics services.
- Real-time websocket push of dashboard updates — MVP uses polling; push is deferred.
- Audit event persistence for report access — Spec 015 may consume analytics events if needed.

---

## Open Questions (Answered)

- [x] **Prior-period comparison in executive summary?** **Decision: Deferred to V2.** MVP ships current-period summary only. The API reserves `date_from`/`date_to` for filtering within the current period; period-over-period comparison is a V2 analytics feature.
- [x] **Real-time vs materialized `average stage delay`?** **Decision: Real-time query from `task_stage_instances` history.** Indexes on `owning_department_id`, `status`, `entered_at`, and `exited_at` support the aggregation. Materialize only if load testing shows a need.
- [x] **Department health thresholds configurable per tenant?** **Decision: Hardcoded defaults in MVP.** Defaults: Red if `overdue > 5` OR `at_risk > 10`; Amber if `at_risk > 3`; else Green. Tenant-level configuration is V2.
- [x] **Team view includes sub-stage assignments?** **Decision: Yes.** `DepartmentDashboardService::team()` aggregates both stage and sub-stage `task_stage_assignments` per employee.
- [x] **Reuse `TaskVisibilityScope` or build a lighter read scope?** **Decision: Reuse `TaskVisibilityScope`.** All analytics queries start from `IntersectsTaskVisibility::baseTaskQuery()`, which applies the existing scope to preserve ABAC and confidentiality rules without divergence.
- [x] **Bottleneck ranking metric?** **Decision: Combined weighted score.** `score = overdue_count × 2 + at_risk_count`, sorted descending with average time-at-stage as tie-breaker. Documented in the API contract.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
