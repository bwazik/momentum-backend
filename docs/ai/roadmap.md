# Implementation Roadmap — Momentum Backend

> Source of truth for backend execution order and stable contracts.
> Aligned with spec IDs in `../frontend/docs/ai/roadmap.md` where UI exists.
> Business truth: `../_blueprints/`

---

## Current Focus

**Active Milestone:** M7 — Documents, Audit, Onboarding & Help
**Active Spec:** `012-documents-attachments`
**Branch:** `main`

Do not implement specs marked ⬜ Not Started unless explicitly instructed.

---

## Milestone Overview

| # | Name | Status | Depends On |
|---|------|--------|------------|
| M1 | Platform & Core Foundation | ✅ Done | — |
| M2 | Organization & IAM | 🔄 In Progress | M1 |
| M3 | Blueprint Engine | ✅ Done | M2 |
| M4 | Task Execution & Lifecycle | 🔄 In Progress | M3 |
| M5 | SLA, Escalation & Notifications | ✅ Done | M4 |
| M6 | Analytics, Follow-up & Search | ✅ Done | M5 |
| M7 | Documents, Audit, Onboarding & Help | ⬜ Not Started | M4 |

**Legend:** ✅ Done · 🔄 In Progress · ⬜ Not Started · 🚧 Blocked

---

## Backend Spec Catalog

| Spec | Milestone | Domain | Frontend pair | Status |
|------|-----------|--------|---------------|--------|
| `001-platform-tenancy` | M1 | Platform + Core tenant resolution | `009-system-administration` (partial) | ✅ Done |
| `001-platform-admin` | M1 | Platform admin auth, tenant CRUD, impersonation, audit events | `009-system-administration` (partial) | ✅ Done |
| `002-organization-structure` | M2 | Organization | `007-organization-structure` | ✅ Done |
| `003-iam-abac` | M2 | IAM | `009-system-administration` | ✅ Done |
| `004-blueprint-engine` | M3 | Blueprint | `004-blueprint-builder` | ✅ Done |
| `005-task-execution` | M4 | Task creation & launch | `002-task-board`, `003-task-details` | ✅ Done |
| `006-stage-lifecycle` | M4 | Stage/sub-stage progression | `003-task-details`, `005-workflow-visualization` | ✅ Done |
| `007-sla-escalation` | M5 | Tracking & SLA | `006-follow-up-center` | ✅ Done |
| `008-notifications` | M5 | Notification | — (backend-only delivery) | ✅ Done |
| `009-analytics-reporting` | M6 | Analytics | `001-executive-dashboard`, `008-analytics-reporting`, `011-department-manager-dashboard` | ✅ Done |
| `010-follow-up-board` | M6 | Follow-up & tracking API | `006-follow-up-center` | ✅ Done |
| `011-search-discovery` | M6 | Search | — | ✅ Done |
| `012-documents-attachments` | M7 | Document | `003-task-details` | ⬜ Not Started |
| `013-comments-collaboration` | M4 | Comments | `003-task-details` | ⬜ Not Started |
| `014-external-references` | M4 | External refs | `002-task-board` | ⬜ Not Started |
| `015-audit-trail` | M7 | Audit | `009-system-administration` | ⬜ Not Started |
| `016-delegation-oof` | M2 | Delegation | — | ⬜ Not Started |
| `017-confidentiality-access` | M2 | Confidential tasks | — | ⬜ Not Started |
| `018-localization-calendar` | M2 | Hijri, working calendar | — | ⬜ Not Started |
| `019-onboarding-training` | M7 | Onboarding | — | ⬜ Not Started |
| `020-help-center` | M7 | Help Center | `010-help-center` | ⬜ Not Started |

---

## M1 — Platform & Core Foundation

**Status:** ✅ Done (including 001-platform-admin supplement)

**Specs:**
- `001-platform-tenancy` — Central tenant registry, DB provisioning, connection switching — ✅ Done
- `001-platform-admin` — Platform admin auth, tenant CRUD, impersonation, audit events — ✅ Done

**M1 established:**
- `tenants` table and central DB connection config
- Tenant resolution middleware (Header → DB switch)
- Template database provisioning workflow
- Redis key prefixing convention (`{slug}:`)
- Base model traits (soft delete, `public_id` generation, timestamps)
- Platform admin authentication (central DB, Sanctum tokens, `RequirePlatformAdmin` middleware)
- Tenant lifecycle API (provision, suspend, reactivate, update, run-migrations)
- Impersonation flow (tenant-scoped Sanctum token with `impersonated-by` ability)
- Central `audit_events` table (append-only, `AuditAction` enum)
- Platform admin CRUD API (create, list, show, update, deactivate, reactivate)
- `PlatformAuthService`, `PlatformTenantService`, `PlatformAdminService`, `PlatformImpersonationService`
- `RunTenantMigrationsJob` (queued, 3 retries with exponential backoff)
- Domain events with `ShouldDispatchAfterCommit`
- `HasRateLimiting` trait on all Platform controllers (matching Organization/IAM pattern)
- `RequireCapability` middleware updated for impersonation detection

**Constraints for later milestones:**
- All tenant business modules use tenant connection only — never central DB for business data
- Queue jobs include tenant slug/id for worker context

---

## M2 — Organization & IAM

**Status:** 🔄 In Progress

**Specs:** `002` ✅, `003` ✅, `016`, `017`, `018`

**Established by 002:**
- `departments` table (nested hierarchy with adjacency list, soft delete, bilingual names)
- `authority_grades` table (permanent seniority levels, no soft delete)
- `positions` table (job slots with reporting lines, department head flag, soft delete)
- `working_calendars` + `public_holidays` tables (working day calculation service)
- `/api/v1/organization/` endpoints (Department, AuthorityGrade, Position, WorkingCalendar, PublicHoliday CRUD)
- Domain events emitted for all mutating actions (consumed by Audit module in Spec 015)
- `TenantModel` updated: UUID v7 for `public_id`, route model binding by `public_id`, SoftDeletes opt-in per model
- `RequireTenantAdmin` middleware replaced by `RequireCapability` (ABAC-based)

**Established by 003:**
- `users` table extended with IAM fields (`public_id`, `name_ar`/`name_en`, `mobile`, `employee_id`, `account_type`, `preferred_language`, `is_active`, `is_out_of_office`, `out_of_office_delegate_user_id`, SoftDeletes)
- `User` model refactored: extends `Authenticatable` + `HasPublicId` trait + `HasApiTokens` + `SoftDeletes`
- `HasPublicId` trait extracted from `TenantModel` to `App\Models\Traits\HasPublicId`
- Authentication: Sanctum token-based login/logout (`POST /v1/iam/auth/login`, `POST /v1/iam/auth/logout`)
- User CRUD (`/api/v1/iam/users`) with bilingual fields, account types, soft-delete deactivate/reactivate
- Position assignments (`user_position_assignments` table, single primary per user, `Position.currentOccupant()`)
- Capability catalog (`capabilities` table, 25 system-defined MVP capabilities via seeder)
- Position capability grants (`position_capability_grants`, scope_type, revocable but not deletable)
- User capability grants (`user_capability_grants`, mandatory reason, scope_type, revocable)
- ABAC Policy Engine (`IamPolicy` singleton, per-request cache, capability + scope resolution)
- Monitoring scope grants (`monitoring_scope_grants`, department + blueprint_category scope)
- Delegations (`delegations` table, scoped by category/stage_type, revocable, `IamPolicy::getActiveDelegate()`)
- Out-of-office toggle (`is_out_of_office` + `out_of_office_delegate_user_id` on users)
- `RequireCapability` middleware replacing `RequireTenantAdmin` on all Organization + IAM routes
- `IamPolicy` registered as singleton with per-request cache, cleared on terminate

**Remaining M2 specs:** `016` (delegation supplement), `017` (confidentiality), `018` (localization/calendar)

---

## M3 — Blueprint Engine

**Status:** ✅ Done

**Specs:** `004` ✅

**Established by 004:**
- `blueprint_categories` table (tenant-defined classification, display order, bilingual)
- `stage_types` table (5 system defaults: Action, Review, Approval, Decision, Information Gathering)
- `sla_policies` table (reusable named SLA templates with duration, unit, warning threshold)
- `blueprints` table (reusable workflow templates with scope org/dept, lock on first task, duplication)
- `blueprint_stages` table (stage definitions with sequence, assignment rules, cardinality, completion rule, escalation override)
- `blueprint_sub_stages` table (optional internal steps with required flag, independent SLA)
- `blueprint_transitions` table (advance/return transitions between stages, with sequence order validation)
- Blueprint lock mechanism (`is_locked` boolean, enforced at service layer for all mutations)
- 6 Blueprint enums: `BlueprintScope`, `AssignmentType`, `AssignmentCardinality`, `CompletionRule`, `SlaUnit`, `TransitionType`
- `/api/v1/blueprints/` endpoints (Category, Stage Type, SLA Policy, Blueprint, Stage, Sub-stage, Transition CRUD)
- `BlueprintService` with duplicate, activate, deactivate, lock-aware update
- `BlueprintCategoryService`, `StageTypeService`, `SlaPolicyService`, `BlueprintStageService`, `BlueprintSubStageService`, `BlueprintTransitionService`
- Caching strategy: tenant-prefixed warm cache (300s) for categories, stage types, SLA policies, active blueprints, blueprint structure
- `blueprint` logging channel
- 8 Blueprint domain exceptions registered in `bootstrap/app.php`
- 20+ domain events implementing `ShouldDispatchAfterCommit`
- Feature tests for all CRUD endpoints, lock behavior, duplicate, reorder, and department-scoped creation

**Constraints for later milestones:**
- Blueprints are immutable after first task launch (locked)
- All task instances store blueprint snapshot at creation time
- SLA policies are template definitions only; runtime timers belong to Spec 007
- Assignment resolution at runtime is Spec 005's responsibility

---

## M4 — Task Execution

**Status:** 🔄 In Progress · **Blocked by:** M3

**Specs:** `005` ✅, `006` ✅, `013` ⬜, `014` ⬜

**Established by 005:**
- `task_priorities`, `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `task_stage_assignments` tables
- 5 Task enums: `TaskStatus`, `ClassificationLevel`, `StageInstanceStatus`, `SubStageInstanceStatus`, `AssignmentRole`
- `TaskPriority` CRUD with cached listing (300s warm), default-swap transaction
- `Task` CRUD (create draft, update draft, soft-delete draft, show, cursor-paginated list)
- Task launch with Stage 1 instance + sub-stage + assignment creation
- Blueprint lock on first task launch
- Assignment resolution: `SpecificPosition`, `DepartmentHead`, `ManualAtLaunch` with delegation check
- `TaskVisibilityScope` — ABAC-aware query scope (org-wide, department-touched, follow-up scope, confidential filter)
- Task lifecycle: suspend/resume/cancel with state machine validation
- `TaskPriorityService`, `TaskService`, `AssignmentResolutionService`
- `TaskController`, `TaskPriorityController`
- 11 domain events implementing `ShouldDispatchAfterCommit`
- 9 domain exceptions extending `DomainException` (refactored to base class)
- 4 factories, 4 feature test files (27 tests), 6 API Resources, 9 Form Requests
- `task` logging channel
- Route file `routes/api/v1/tasks.php`
- 2 new capabilities: `task.manage_priorities`, `task.manage`
- 3 default priorities seeded: Critical, Urgent, Routine
- `DomainException` base class (refactored 33 exceptions across all modules)

**Established by 006:**
- `StageLifecycleService` — stage/sub-stage complete, return, assignment override, history, timeline
- `StageLifecycleController` — 10 endpoints for stage lifecycle operations
- 9 domain events: `StageAssignmentCompleted`, `StageInstanceCompleted`, `StageInstanceAdvanced`, `StageInstanceReturned`, `SubStageAssignmentCompleted`, `SubStageInstanceCompleted`, `SubStageInstanceReturned`, `StageAssignmentOverridden`, `TaskCompleted`
- 7 domain exceptions: `StageNotActiveException`, `SubStageNotActiveException`, `UserNotAssigneeException`, `InvalidReturnTargetException`, `RequiredSubStagesIncompleteException`, `InvalidSubStageReturnTargetException`, `AssigneeNotFoundForOverrideException`
- 4 form requests: `CompleteStageRequest`, `ReturnStageRequest`, `ReturnSubStageRequest`, `OverrideAssignmentRequest`
- 2 API resources: `StageReturnResource`, `TaskTimelineResource`
- Additive migration: `completion_note` column on `task_stage_assignments`
- Completion rule evaluation: `AnyAssignee`, `AllAssignees`, `LeadAssignee` — all 3 tested
- Stage advance via `blueprint_stage_transitions` with fallback to `sequence_order`
- Stage return creates new instance (history preserved), cancels active sub-stages
- Sub-stage completion auto-activates next sequential sub-stage
- Sub-stage return via `sequence_order` comparison (no explicit transition table)
- Required sub-stages block stage completion (422)
- Assignment override with `task.override_assignment` capability check (403 without)
- `StageAssignmentCreated` event emitted on override (added during implementation review)
- Manual-at-launch re-entry reuses original assignees via historical lookup
- Task auto-completed on final stage completion (`status=completed`, `completed_at`)
- Timeline endpoint aggregates stage entries, exits, assignments, overrides chronologically
- 5 feature test files, 21 tests (87 assertions) covering all scenarios
- `authorizeTaskVisibility` on all mutating endpoints (ABAC consistency)
- ABAC visibility enforced on all read endpoints (`stages`, `showStage`, `returns`, `timeline`)
- `completion_note` per-assignment stored on `task_stage_assignments` + last-writer copy on stage instance
- Routes: all 10 lifecycle routes appended to `routes/api/v1/tasks.php`

**Will establish (remaining):** comments (013), external references (014)

---

## M5 — SLA, Escalation & Notifications

**Status:** ✅ Done

**Specs:** `007` ✅, `008` ✅

**Established by 007:**
- **Tracking module** (`app/Modules/Tracking/`) — clean bounded context
- `sla_timer_instances` table (FKs to tasks, stage/sub-stage instances, SLA policies, working calendars; composite indexes for pause/resume and scheduler scans)
- `escalations` table (FKs to tasks, stage/sub-stage instances, timers, users, positions; indexed by task+status and target user+status)
- 3 enums: `SlaTimerStatus` (Running/Warning/Breached/Completed/Paused), `EscalationType` (AutoSlaBreach/Manual), `EscalationStatus` (Open/Resolved)
- `SlaTimerInstance` and `Escalation` models with casts, relationships, scopes (`active`, `forTask`, `dueWarning`, `dueBreach`, `open`)
- `SlaTimerService` — timer create/pause/resume/complete with working-calendar-aware deadline calculation
- `SlaThresholdService` — warning and breach detection via `chunkById` + `lockForUpdate` (safe under concurrent scheduler)
- `SlaEscalationService` — auto-escalation on breach (Blueprint `escalation_position_id` → assignee `reports_to_position_id`), manual escalation with duplicate check, resolution with ABAC
- `SlaTimerController` — `taskHealth` (bounded), `index` (cursor-paginated, filtered)
- `EscalationController` — `index` (cursor-paginated, filtered), `show`, `store`, `resolve`
- 8 listeners consuming Task events (stage/sub-stage created/completed/returned, task suspended/resumed/completed/cancelled) — all idempotent, all log to `tracking` channel
- `TrackingServiceProvider` (registers event listeners)
- `CheckSlaTimersCommand` + `CheckSlaTimersJob` (scheduled per-tenant SLA threshold scanning, `tries=3`, `backoff=[30,60,120]`)
- 8 domain events implementing `ShouldDispatchAfterCommit`
- 7 domain exceptions extending `DomainException`
- 4 form requests: `ListSlaTimersRequest`, `ListEscalationsRequest`, `CreateManualEscalationRequest`, `ResolveEscalationRequest`
- 4 API resources: `SlaTimerInstanceResource`, `TaskSlaHealthResource`, `EscalationResource`, `EscalationDetailResource`
- 2 new capabilities: `task.escalate`, `task.resolve_escalations`
- Route file `routes/api/v1/tracking.php` with 6 endpoints
- `tracking` logging channel in `config/logging.php`
- 2 factories (`SlaTimerInstanceFactory`, `EscalationFactory`)
- 7 feature test files, 20 tests (81 assertions)
- `WorkingDayCalculator` extended with `addWorkingHours()`, `addWorkingSeconds()`, `workingSecondsBetween()`
- Pagination response shape aligned with coding-standards: `{data, next_cursor, has_more}` on all list endpoints
- Responsive cache keys for holiday lookups (tenant-prefixed, cold TTL 3600s)

**Established by 008:**
- **Notification module** (`app/Modules/Notification/`) — clean bounded context consuming Task + Tracking events
- `notifications` table in tenant DB (UUID `id` PK, `morphs`, `data`, `read_at`, timestamps; composite `(notifiable_type, notifiable_id, read_at)` index)
- 2 enums: `NotificationType` (string-backed, 10 cases), `NotificationChannel` (int-backed)
- 10 notification classes implementing `ShouldQueue` with `tries=3` `backoff=[30,60,120]`, `via() = ['database', 'mail']`, bilingual `toArray()` and locale-aware `toMail()`
- 10 auto-discovered listeners resolving recipients via read-only `NotificationRecipientResolver`, dedupe-guarded via `data.dedupe_key`
- `NotificationRecipientResolver` — read-only: `activeStageAssignees()`, `activeTaskParticipants()`, `initiator()`
- `NotificationReadService` — cached unread count (60s, tenant-prefixed), mark single/mark-all with cache invalidation
- `NotificationController` with `HasRateLimiting`: cursor-paginated list (`LIST` 60/min), unread count (`LIST`), mark read/mark all read (`MUTATE` 30/min)
- `NotificationResource` exposing UUID `id`, `type`, `data`, `read_at`, `created_at`
- `ListNotificationsRequest` validating `read` filter (`unread`/`read`/`all`) and `per_page`
- `routes/api/v1/notifications.php` with 4 endpoints, registered in `routes/tenant.php`
- Bilingual translation files `lang/{ar,en}/notifications.php` for all 10 types
- `notification` logging channel in `config/logging.php`
- 3 feature test files, 23 tests (51 assertions): delivery, API, localization, isolation, idempotency

**Constraints for later milestones:**
- SLA timer and escalation records are historical and never soft-deleted
- Warning/breach events emitted once per timer (idempotent via `lockForUpdate`)
- Scheduled SLA check is safe to run concurrently (per-timer transactions + row locks)
- No caching on timer/escalation list endpoints (time-sensitive)
- `resolveWorkingCalendar` currently uses tenant default only; department-level resolution deferred pending `departments.working_calendar_id` column
- Notification module never writes Task/Tracking/IAM/Organization tables (read-only cross-module)

---

## M6 — Analytics, Follow-up & Search

**Status:** ✅ Done

**Specs:** `009` ✅, `010` ✅, `011` ✅

**Established by 010:**
- **FollowUp module** (`app/Modules/FollowUp/`) — read-heavy operational layer for follow-up specialists
- `follow_up_actions` table (append-only log: `public_id`, FKs to `tasks`/`users`, `action_type`, `note_ar`, `note_en`, `contact_name`, timestamps)
- 4 enums: `FollowUpActionType`, `SlaHealth`, `BoardSortField`, `BoardSortDirection`
- `FollowUpBoardService` — ABAC-filtered board query with filters (status, stage type, assignee, department, priority, category, date range, search), sort options, and SLA health / time-at-stage enrichment
- `FollowUpActionService` — create/list follow-up actions with `TaskVisibilityScope` and capability checks
- 6 endpoints under `/api/v1/follow-up`: `board`, `overdue`, `at-risk`, `bottlenecks`, `tasks/{task}/actions` (GET/POST)
- `FollowUpActionCreated` event implementing `ShouldDispatchAfterCommit`
- Bottleneck cache at `{tenant_slug}:followup:bottlenecks:{user_public_id}:{department_id}:{limit}` TTL 300s, invalidated by Task/Tracking lifecycle listeners
- `followup` logging channel in `config/logging.php`
- 2 feature test files, 29 tests (87 assertions)
- `openapi/openapi.json` regenerated with follow-up endpoints

**Established by 009:**
- **Analytics module** (`app/Modules/Analytics/`) — pure read-only reporting bounded context
- `ExecutiveDashboardService`, `DepartmentDashboardService`, `AgingReportService` with shared `IntersectsTaskVisibility` trait
- `TaskHealth` and `DepartmentHealth` enums
- 9 analytics endpoints under `/api/v1/analytics`: executive summary, bottlenecks, department health, summary/bottleneck drill-downs, department performance/team/drill-down, task aging
- `TaskVisibilityScope` applied before aggregation so ABAC/confidentiality rules are preserved
- Tenant-prefixed cache keys (300s warm tier) for executive summary, department health, department performance, and team metrics; event-driven invalidation via auto-discovered listeners
- `analytics` logging channel in `config/logging.php`
- 4 feature test files, 18 tests (54 assertions): executive dashboard, department dashboard, aging report, ABAC/confidentiality
- `openapi/openapi.json` regenerated and contract marked `stable`

**Established by 011:**
- **Search module** (`app/Modules/Search/`) — pure read-only bounded context for full-text task discovery
- `SearchActivityType` enum: `TaskViewed` (1), `StageCompleted` (2), `StageReturned` (3), `CommentAdded` (4) — int-backed, stored as TINYINT
- `task_search_index` table: denormalized completion notes per task with `tsvector` generated columns (`search_vector_notes_ar`, `search_vector_notes_en`) and GIN indexes
- `user_recent_activity` table: append-only activity log with composite index on `(user_id, occurred_at DESC)`
- 3 additive tenant migrations: search vectors on `tasks`, `task_search_index`, `user_recent_activity`
- `SearchService` — `searchTasks()` uses PostgreSQL `ts_rank`/`to_tsquery` full-text search across bilingual title, description, and denormalized notes; `recentActivity()` uses `DISTINCT ON (task_id)` with LIMIT 20
- `TaskViewed` domain event in Task module (`ShouldDispatchAfterCommit`)
- `TaskService::findVisible()` — centralized visibility-scoped task lookup that emits `TaskViewed`
- `TaskController::show()` — delegates to `findVisible()`, adds `RateLimits::LIST` (60/min)
- `StageInstanceReturned` event extended with `User $returnedByUser` parameter
- 4 queued listeners (`ShouldQueue`, `$tries=3`, `$backoff=[30,60,120]`): `UpdateSearchIndexOnStageAssignmentCompleted`, `RecordActivityOnTaskViewed`, `RecordActivityOnStageAssignmentCompleted`, `RecordActivityOnStageInstanceReturned`
- `SearchIndexService` — idempotent upsert of completion notes aggregated from `task_stage_assignments`
- `SearchActivityService` — append activity rows with 5-minute `TaskViewed` deduplication window
- `SearchTasksRequest` — validation for `q` (required, 2–200 chars), status, priority_id, date range, department_id, blueprint_id, blueprint_category_id, external_reference, per_page
- `SearchTaskResource` — enriched response with `public_id`, bilingual title, status, priority, classification_level, current_stage, department, blueprint_category, due_date, created_at, snippet fields
- `RecentActivityResource` — exposes `activity_type` name and `occurred_at` alongside task metadata
- `SearchController` — `tasks()`, `search()` (alias), `recent()` endpoints with `HasRateLimiting` trait
- `PruneRecentActivityCommand` — scheduled daily (`search:prune-recent-activity`, configurable `--days=90`)
- 2 domain exceptions: `SearchQueryTooShortException` (422), `ExternalReferenceSearchNotAvailableException` (422)
- `search` logging channel in `config/logging.php` (daily, 14-day retention)
- Routes: `GET /v1/search`, `GET /v1/search/tasks`, `GET /v1/search/recent` under `auth:sanctum` middleware
- 20 feature tests (44 assertions): search validation, text matching, filters, ABAC, pagination, recent activity dedup, tenant isolation, prune command
- OpenAPI spec regenerated at `openapi/openapi.json`
- Comment indexing deferred to Spec 013; external reference search returns 422 until Spec 014
- `withCommands()` auto-discovery in `bootstrap/app.php` scans all module command directories
- SQLite fallback in `SearchService` for test/dev environments (LIKE-based search instead of FTS)

---

## Dependency Map

```
M1: Platform & Core
  └── TenantResolver ─────────────────────────────────┐
  └── Central tenants registry ───────────────┐       │
                                              ↓       ↓
M2: Organization & IAM ─────────────────────────────────────────┐
  └── ABAC PolicyEngine ────────────────────────┐               │
  └── Positions / Departments ──────────┐       │               │
                                        ↓       ↓               ↓
M3: Blueprint Engine ─────────────────────────────────────────┤
  └── Blueprint definitions + lock ────────────────┐           │
                                                     ↓           │
M4: Task Execution ─────────────────────────────────────────────┤
  └── Task + StageInstance services ────────────────┐          │
                                                      ↓          │
M5: SLA & Notifications ────────────────────────────────────────┤
                                                      ↓          │
M6: Analytics & Follow-up ──────────────────────────────────────┤
                                                      ↓          │
M7: Documents, Audit, Help, Onboarding ─────────────────────────
```

---

## Rules for the AI Agent

- Never implement ⬜ specs without explicit instruction
- Before changing M1 contracts after ✅, check downstream dependents
- Update this file when a spec reaches ✅ Done — extract contracts from `plan.md`
- Update `openapi/openapi.json` when `Contract status: stable`
- Backend leads frontend — mark API `stable` before frontend implements against it

---

→ **Next:** [architecture.md](architecture.md)
