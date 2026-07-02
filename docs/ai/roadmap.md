# Implementation Roadmap — Momentum Backend

> Source of truth for backend execution order and stable contracts.
> Aligned with spec IDs in `../frontend/docs/ai/roadmap.md` where UI exists.
> Business truth: `../_blueprints/`

---

## Current Focus

**Active Milestone:** M2 — Organization & IAM
**Active Spec:** `018-localization-calendar`
**Branch:** `main`

Do not implement specs marked ⬜ Not Started unless explicitly instructed.

---

## Milestone Overview

| # | Name | Status | Depends On |
|---|------|--------|------------|
| M1 | Platform & Core Foundation | ✅ Done | — |
| M2 | Organization & IAM | 🔄 In Progress | M1 |
| M3 | Blueprint Engine | ✅ Done | M2 |
| M4 | Task Execution & Lifecycle | ✅ Done | M3 |
| M5 | SLA, Escalation & Notifications | ✅ Done | M4 |
| M6 | Analytics, Follow-up & Search | ✅ Done | M5 |
| M7 | Documents, Audit, Onboarding & Help | 🔄 In Progress | M4 |

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
| `012-documents-attachments` | M7 | Document | `003-task-details` | ✅ Done |
| `013-comments-collaboration` | M4 | Comments | `003-task-details` | ✅ Done |
| `014-external-references` | M4 | External refs | `002-task-board` | ✅ Done |
| `015-audit-trail` | M7 | Audit | `009-system-administration` | ✅ Done |
| `016-delegation-oof` | M2 | Delegation | — | ✅ Done |
| `017-confidentiality-access` | M2 | Confidential tasks | — | ✅ Done |
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

**Specs:** `002` ✅, `003` ✅, `016` ✅, `017` ✅, `018`

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

**Established by 016:**
- `IamPolicy::resolveDelegateForAssignment()` — scoped delegation resolution (all 4 `DelegationScopeType` cases) with OOF fallback
- `AssignmentResolutionService` integration — context-aware delegation routing for stage and sub-stage assignments
- Conditional scope validation on `StoreDelegationRequest` / `UpdateDelegationRequest` (`blueprint_category_id`, `stage_type_id`)
- `DelegationScopeMismatchException` — 422 for invalid scope field combinations
- `DelegationExpired` event + `DelegationExpiryService` + `ExpireDelegationsCommand` / `ExpireDelegationsJob` — auto-expiry via scheduler
- `GET /api/v1/iam/delegations/active` — cursor-paginated active delegation listing with filters
- `active_now` filter on `GET /api/v1/iam/delegations` — cursor-paginated when active
- `iam.view_delegations` capability — read-only delegation access
- `RequireCapability` middleware supports `|`-separated OR logic for multiple capabilities
- `Delegation` model relationships: `blueprintCategory()`, `stageType()`
- `DelegationResource` exposes `blueprint_category` and `stage_type` when loaded

**Established by 017:**
- `task_confidential_participants` table — named participant model with `removed_at` for history
- `confidential_governance_participants` table — automatic governance participant configuration (position-based, scoped by department/category)
- `confidential_access_events` table — append-only log of all confidential access events (metadata views, overrides, participant changes)
- `ConfidentialAccessEventType` enum — 4 cases: `MetadataView`, `ContentOverride`, `ParticipantAdded`, `ParticipantRemoved`
- `TaskVisibilityScope` updated — full classification enforcement: `public` (unchanged), `internal` (blocks lateral uninvolved), `confidential` (strict allow-list: tenant admin, initiator, assignee, named participant, governance participant, external auditor, override)
- Classification rules enforced at the database layer via `TaskVisibilityScope` — reused by Task, Search, Analytics, FollowUp, Comment, Document, and Stage History without per-module changes
- Named participant management API (`POST/GET/DELETE /api/v1/tasks/{task}/confidential-participants`)
- Governance participant config API (`POST/GET/PUT /api/v1/iam/confidential-governance-participants`, `POST .../revoke`)
- Redacted metadata view (`GET /api/v1/tasks/{task}/metadata`) — requires `task.confidential.view_metadata`
- Audited content override (`POST /api/v1/tasks/{task}/access-override`) — requires `task.confidential.view_override` + mandatory reason
- Cached governance config at `{tenant_slug}:iam:confidential_governance_participants:all` (300s TTL, invalidated on mutations)
- 7 domain events implementing `ShouldDispatchAfterCommit` + `ProvidesAuditData` — auto-recorded by Audit module
- 6 domain exceptions extending `DomainException` (rendered by base handler)
- `IamPolicy::getAuditGrantDepartmentIds()` — external auditor department resolution for confidential task access
- Bilingual translations in `lang/{en,ar}/{task,iam}.php` for all exception messages
- Tenant settings `settings.confidentiality.initiator_can_manage_participants` and `settings.confidentiality.metadata_show_actual_title` with safe defaults
- 33 feature tests (83 assertions): participant CRUD, metadata/view/override happy paths, governance config lifecycle, scoping validation, visibility enforcement, cursor pagination

**Remaining M2 specs:** `018` (localization/calendar)

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

**Status:** ✅ Done

**Specs:** `005` ✅, `006` ✅, `013` ✅, `014` ✅

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

**Established by 013:**
- `comments` table with self-referential FK for single-level threading (parent_comment_id), soft deletes, composite indexes on (task_id) and (task_id, parent_comment_id)
- `Comment` model in the Task module with task/user/parent/replies/documents relationships, extending `TenantModel` with `HasPublicId` and `SoftDeletes`
- `CommentService` — create (with parent validation: same-task + top-level only) and list (cursor-paginated top-level comments with nested replies as full list)
- `CommentController` with `HasRateLimiting` — authorize via `TaskVisibilityScope`, `RateLimits::LIST` (60/min) and `RateLimits::MUTATE` (30/min)
- `CommentResource` — JSON shape with author (public_id, name_ar, name_en), body, parent_comment_id, created_at, attachment_count, nested replies
- `StoreCommentRequest` — validates body (required, string, max 5000) and optional parent_comment_id (exists:comments,public_id)
- `CommentCreated` domain event implementing `ShouldDispatchAfterCommit` and `ProvidesAuditData` — auto-recorded by Audit module
- 2 domain exceptions: `InvalidCommentParentException` (422), `CommentNotFoundException` (404)
- `InvalidCommentParentException` rendered as 422 with bilingual message from `lang/{en,ar}/task.php`
- Comment attachments: `POST/GET /api/v1/comments/{comment}/documents` activated via `DocumentService::uploadForComment()` and `DocumentAttachmentController` methods
- `DocumentService::resolveTask()` updated to handle `DocumentEntityType::Comment` — resolves parent task via `Comment::find($entityId)?->task`
- `task_search_index` additive migration: `comment_content_ar`, `comment_content_en` columns with generated `tsvector` columns and GIN indexes (PostgreSQL only)
- `SearchIndexService::upsertForTask()` aggregates all non-deleted comment bodies into `comment_content_ar`/`comment_content_en`
- `SearchService::searchTasks()` — FTS ranking and WHERE clause include comment vectors; SQLite fallback includes comment content columns
- 2 queued listeners (ShouldQueue, $tries=3, $backoff=[30,60,120]): `UpdateSearchIndexOnCommentCreated`, `RecordActivityOnCommentCreated`
- `SearchActivityService::recordCommentAdded()` — writes `SearchActivityType::CommentAdded` to `user_recent_activity`
- No caching on comment lists (write-heavy, real-time expectation)
- `CommentFactory` + 19 feature tests (63 assertions): create, reply, nesting validation, task-scope parent validation, ABAC denial, confidential task restriction, cursor pagination, document upload/list, manage capability enforcement, search index update, recent activity, audit event, rate limiting
- All list endpoints return cursor-paginated `{data, next_cursor, has_more}` shape
- Routes registered in `routes/api/v1/tasks.php` and `routes/api/v1/documents.php`
- `task` logging channel used for all comment service operations

**Established by 014:**
- `external_entities` and `task_external_references` tables (tenant DB, soft deletes, no `tenant_id` columns)
- 2 enums: `ExternalEntityType` (8 cases), `ExternalReferenceType` (8 cases) — both with `apiValue()` method
- `ExternalEntity` and `TaskExternalReference` models extending `TenantModel` with `HasFactory` and `SoftDeletes`
- `ExternalEntityService` — CRUD + deactivate/reactivate with warm-cache active entity list (`{tenant_slug}:task:external_entities:active`, TTL 300s, invalidated on mutations)
- `TaskExternalReferenceService` — create/update/delete with active-entity validation and cursor-paginated listing
- `ExternalEntityController` and `TaskExternalReferenceController` with `HasRateLimiting` trait (`LIST` 60/min, `MUTATE` 30/min)
- 4 Form Requests with `Rule::enum()` validation
- 2 API Resources (`ExternalEntityResource`, `TaskExternalReferenceResource`) exposing `public_id` and `apiValue()` strings
- 7 domain events implementing `ShouldDispatchAfterCommit` + `ProvidesAuditData`: `ExternalEntityCreated/Updated/Deactivated/Reactivated`, `ExternalReferenceCreated/Updated/Deleted`
- 4 domain exceptions: `ExternalEntityNotFoundException` (404), `ExternalEntityInactiveException` (422), `ExternalReferenceNotFoundException` (404), `TaskNotVisibleException` (403) — all extending `DomainException` with bilingual messages
- `AuditEntityType` extended with `ExternalEntity = 32` and `ExternalReference = 33`
- `task.manage_external_entities` capability seeded in `CapabilitySeeder`
- 10 routes under `/api/v1/tasks/` — entities before `{task}` wildcard; references nested under `{task}`
- Search integration: `ExternalReferenceSearchNotAvailableException` removed, `external_reference` filter eager-loads matched references, `external_references` exposed in `SearchTaskResource`; `q` changed to `required_without:external_reference`
- Bilingual translations in `lang/{en,ar}/task.php` for all 4 exception messages
- `ExternalEntityFactory`, `TaskExternalReferenceFactory`
- 24 feature tests (76 assertions): entity CRUD, reference CRUD, inactive entity rejection, ABAC denial (visibility + mutation), confidential task restriction, search by reference, standalone `external_reference` without `q`, full-text `q` not matching reference numbers, audit event recording, cache invalidation, `name_en` preservation on partial update
- All service methods use `try/catch` + `Log::channel('task')`
- No `tenant_id` columns, no cross-module ORM joins, cursor pagination on reference lists, full list on entities
- `openapi/openapi.json` regenerated with new endpoints

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

## M7 — Documents, Audit, Onboarding & Help

**Status:** 🔄 In Progress

**Specs:** `012` ✅, `015` ✅, `019` ⬜, `020` ⬜

**Established by 012:**
- **Document module** (`app/Modules/Document/`) — clean bounded context for attachment metadata and file storage
- `documents` tenant table: polymorphic (entity_type/entity_id), version chain (root_document_id/parent_document_id/version_number), soft-deletes, composite indexes on `(entity_type, entity_id)` and `(root_document_id, version_number)`
- 2 enums: `DocumentEntityType` (Task/Comment/StageOutput/HelpArticle), `DocumentMimeCategory` (Pdf/Image/Word/Excel/Other) with `fromMimeType()` and `supportsPreview()` methods
- `Document` model extending `TenantModel` with `HasFactory` + `SoftDeletes`, self-referential version relationships, `nextVersion()` / `currentVersions()` scope (returns leaf nodes = latest version)
- `DocumentStorageService` — filesystem-agnostic wrapper around Laravel `Storage` facade; stores at `{tenant_slug}/documents/{public_id}/` path; no direct S3/MinIO SDK calls
- `DocumentService` — upload (store before DB create), versioning, list/show, download/preview, soft-delete with task visibility enforcement via `TaskVisibilityScope`
- 2 controllers: `DocumentAttachmentController` (entity-scoped upload/list) and `DocumentController` (show/download/preview/version/delete)
- 2 form requests: `UploadDocumentRequest`, `UploadDocumentVersionRequest` — MIME type validation, max size from tenant settings (default 20 MB)
- 2 API resources: `DocumentResource` (public_id, bilingual uploader, download/preview URLs), `DocumentVersionResource` (version history)
- 5 domain events (`DocumentUploaded`, `DocumentVersionCreated`, `DocumentDownloaded`, `DocumentPreviewed`, `DocumentDeleted`) all implementing `ShouldDispatchAfterCommit`; `DocumentDeleted` includes `chainRootId`
- 3 domain exceptions: `DocumentNotFoundException` (404), `UnsupportedPreviewTypeException` (422), `StorageProviderException` (500), auto-rendered via base `DomainException` handler
- 2 new capabilities seeded: `task.manage_documents`, `task.view_documents`
- `document` logging channel in `config/logging.php` (daily, 14-day retention)
- Route file `routes/api/v1/documents.php` with 12 endpoints (6 active + 2 comment routes deferred)
- `HasRateLimiting` trait on both controllers with `RateLimits::MUTATE` (uploads/versions/deletes) and `RateLimits::LIST` (reads)
- Cursor pagination (`{data, next_cursor, has_more}`) on all list endpoints
- No `tenant_id` column on `documents` table (database-per-tenant isolation)
- Tenant storage cleanup in tests via `cleanupTenantStorage()` helper
- `route:tenant.php` updated with documents route registration
- 14 feature tests (53 assertions): upload, list, version, download, preview, soft-delete, capability enforcement, validation, cursor pagination, version chain
- `storage/tenant*/` added to `.gitignore`

**Established by 015:**
- **Audit module** (`app/Modules/Audit/`) — clean bounded context for immutable append-only event logging
- `audit_events` tenant table with `event_type`, `entity_type` (TINYINT enum), `entity_id`, `entity_public_id`, `root_entity_*` denormalized columns, `impersonated_by_public_id`, and 4 composite indexes
- `App\Modules\Audit\Enums\AuditEntityType` — int-backed enum (31 cases for all entity categories + central entities)
- `ProvidesAuditData` interface — each auditable event implements `auditData(): AuditEventData`
- `AuditEventData` DTO — typed data transfer object with all audit fields
- `RecordAuditEvent` listener (~60 lines) — checks interface, calls `auditData()`, persists row; catches `\Throwable`, never re-throws
- `AuditServiceProvider` — auto-discovers `ProvidesAuditData` implementors across all tenant modules
- `AuditEventService` — three read APIs (task trail, system log, my activity) with cursor pagination, ABAC, `TaskVisibilityScope`, and external auditor support
- `CentralAuditServiceProvider` + `RecordCentralAuditEvent` — central audit aligned with same interface pattern; 13 old dedicated listeners replaced by generic listener
- All 92+ tenant domain events (Task, IAM, Organization, Blueprint, Document, FollowUp, Tracking) and 13 Platform events now implement `ProvidesAuditData`
- `audit` logging channel (daily, 30-day retention)
- 3 read endpoints: `GET /v1/tasks/{task}/audit-trail`, `GET /v1/audit-trail/system`, `GET /v1/audit-trail/me`
- Rate limiting via `HasRateLimiting` + `RateLimits::LIST` on all endpoints
- Cursor pagination (`{data, next_cursor, has_more}`) on all list endpoints
- Central `audit_events` table updated with aligned schema (`event_type`, `entity_type_int`, `root_entity_*`, `impersonated_by_public_id`, `action` made nullable)
- `Platform\Models\AuditEvent` updated with immutability guards
- 5 feature test files (26 tests, 70 assertions): happy path, ABAC denials, external auditor, pagination, IP/UA privacy, append-only, listener safety, tenant isolation, impersonation persistence
- 26 test files cleaned up across all modules (removed redundant `forceDelete`/`delete` in `afterEach`, fixed `MockDataSeeder` self-healing, fixed enum `apiValue()` assertions, fixed SQLite `:memory:` migration conflicts)

**Will establish (remaining M7):** onboarding (019), help center (020)

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
