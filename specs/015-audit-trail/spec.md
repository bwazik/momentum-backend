# Spec: Audit Trail

> **Number:** 015
> **Date:** 2026-06-30
> **Status:** `completed`
> **Milestone:** M7 — Documents, Audit, Onboarding & Help
> **Depends on:** `001-platform-admin` (central audit_events pattern, impersonation metadata); `002-organization-structure` (department context for events); `003-iam-abac` (users, ABAC, audit grants); `004-blueprint-engine` (blueprint lifecycle events); `005-task-execution` (task lifecycle events); `006-stage-lifecycle` (stage/sub-stage events); `007-sla-escalation` (SLA timer/escalation events); `008-notifications` (notification events — optional); `010-follow-up-board` (follow-up action events); `011-search-discovery` (TaskViewed events); `012-documents-attachments` (document lifecycle events)
> **Provides APIs:** Task audit trail list (`GET /api/v1/tasks/{task}/audit-trail`), system activity log list (`GET /api/v1/audit-trail/system`), personal activity list (`GET /api/v1/audit-trail/me`)
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/010-system-administration`
> **Author:** OpenCode
> **Branch:** `feat/015-audit-trail`
> **Base branch:** `main`

---

## Problem

Government organizations and large enterprises in the GCC operate under strict compliance and state-audit-bureau oversight. Every action on a task — creation, launch, stage advance/return, assignment override, suspension, comment, file upload, escalation — must be reconstructible with who did what, when, from which IP, and with what before/after payload. Without a unified, immutable audit trail, compliance officers cannot defend decisions, internal audit cannot investigate incidents, and external auditors cannot verify that processes were followed.

Individual modules already emit domain events (Task, Tracking, IAM, Organization, Blueprint, Document, FollowUp, Search). Today those events are consumed by Notification and Analytics, but no module persists them as a permanent, queryable, tamper-evident log. This spec creates the Audit module: a tenant-scoped, append-only event store plus read APIs that enforce ABAC visibility rules.

---

## Goal

Deliver an Audit module that subscribes to domain events from all tenant modules and writes them to an immutable `audit_events` table in the tenant database. Provide authorized users with:

- A chronological per-task audit trail (all events for a given task `public_id`).
- A system-wide user activity log filtered by user, action, entity type, and date range.
- A personal activity view ("My actions").

The module must never mutate or delete audit rows, must respect task visibility and confidential-task rules, must support external-auditor access scoped by existing `audit_grants`, and must remain read-only to other modules at runtime.

---

## User Stories

### Compliance & Internal Audit

- As a **compliance officer**, I want to view the complete audit trail of any task I can see, so that I can reconstruct decisions and prove process adherence.
- As an **internal auditor**, I want to filter system activity by user, action type, entity, and date range, so that I can investigate suspicious or incorrect actions.
- As a **tenant admin**, I want to know that audit records cannot be edited or deleted by anyone, so that the log remains trustworthy.

### Task Participants

- As a **task viewer**, I want to see a chronological history of stage advances, returns, overrides, and comments for a task, so that I understand how it reached its current state.
- As a **stage assignee**, I want my completion notes and actions to appear in the audit trail, so that my accountability is visible.

### External Audit

- As an **external auditor** with an active audit grant, I want to view the audit trail of completed or archived tasks covered by my grant, so that I can perform my audit without accessing live tasks.

---

## Acceptance Criteria

### `audit_events` Table

- [x] `audit_events` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `event_type` (VARCHAR 100), `entity_type` (unsignedTinyInteger, maps to `AuditEntityType` enum), `entity_id` (BIGINT, polymorphic internal id), `entity_public_id` (UUID v7, nullable, for API display), `root_entity_type` (unsignedTinyInteger, nullable), `root_entity_id` (BIGINT, nullable), `root_entity_public_id` (UUID, nullable), `user_id` (FK `users`, nullable for system actions), `ip_address` (VARCHAR 45), `user_agent` (VARCHAR 500), `payload` (JSONB), `impersonated_by_public_id` (nullable, UUID string, for impersonation context), `created_at`
- [x] Append-only: no UPDATE or DELETE endpoints or model methods; application layer enforces immutability
- [x] Composite index on `(entity_type, entity_id, created_at)` for entity-level queries
- [x] Composite index on `(root_entity_type, root_entity_id, created_at)` for per-task trail queries
- [x] Composite index on `(user_id, created_at)` for user activity queries
- [x] Composite index on `(event_type, created_at)` for action-type filtering
- [x] No `tenant_id` column (database-per-tenant isolation)

### Audit Event Capture

- [x] Each domain event implements `App\Modules\Audit\Contracts\ProvidesAuditData` and provides an `auditData(): AuditEventData` method
- [x] `App\Modules\Audit\Listeners\RecordAuditEvent` checks for the `ProvidesAuditData` interface, calls `auditData()`, and persists the result
- [x] Each event defines its own `event_type` using `{module}.{action}` convention (e.g., `task.created`, `stage.completed`, `document.downloaded`)
- [x] Each event returns `entity_type`, `entity_id`, `entity_public_id`, `root_entity_type`, `root_entity_id`, `root_entity_public_id`, and `payload` via `AuditEventData`
- [x] For task-related child events (stage, sub-stage, document, escalation, SLA timer, follow-up action), `root_entity_type` = `task` and `root_entity_id` = the task's internal id
- [x] Listener records `user_id`, `ip_address`, `user_agent` from request context where available; system actions store null `user_id`
- [x] Listener records `impersonated_by_public_id` when current request is an impersonation session (platform admin public_id from token abilities)
- [x] Events from central/platform actions are stored in central `audit_events` via `RecordCentralAuditEvent` listener (aligned with tenant schema); this spec only stores tenant module events

### Capabilities

- [x] New system capability `audit.view_task` — view audit trail of visible tasks
- [x] New system capability `audit.view_system` — view system-wide user activity log
- [x] Capabilities seeded via `CapabilitySeeder`
- [x] External auditor access uses existing `audit_grants` (managed in IAM Spec 003) — no new grant management in this spec

### Task Audit Trail API

- [x] `GET /api/v1/tasks/{task}/audit-trail` returns cursor-paginated audit events for the task and its child entities, newest first (filtered by `root_entity_type = task` and `root_entity_id = task.id`)
- [x] Response includes: `event_type`, `entity_type`, `entity_id` (public_id), `root_entity_type`, `root_entity_id`, `performed_by` (user public_id, name), `ip_address`, `user_agent`, `payload`, `impersonated_by`, `created_at`
- [x] Enforces task visibility via `TaskVisibilityScope` and requires `audit.view_task`; 403 otherwise
- [x] Confidential task audit trail visible only to users who can view the confidential task
- [x] External auditors may call this endpoint only for tasks covered by an active `audit_grant` and only completed or cancelled tasks

### System Activity Log API

- [x] `GET /api/v1/audit-trail/system` returns cursor-paginated system activity events
- [x] Query params: `user_id`, `event_type`, `entity_type`, `date_from`, `date_to`
- [x] Requires `audit.view_system`; 403 otherwise
- [x] Never returns payload content of confidential tasks to users without confidential-task visibility

### My Activity API

- [x] `GET /api/v1/audit-trail/me` returns cursor-paginated events where `user_id` = current user
- [x] Query params: `event_type`, `entity_type`, `date_from`, `date_to`
- [x] Available to all authenticated internal users

### Events & Audit

- [x] `AuditEventRecorded` domain event emitted after each audit row insert (for potential downstream indexing in V2)
- [x] Listener handling is idempotent in the sense that duplicate events from retries result in duplicate rows (audit is append-only) without errors

---

## Non-Functional Requirements

### Pagination

Per `coding-standards.md` § Pagination:

- `GET /api/v1/tasks/{task}/audit-trail`, `GET /api/v1/audit-trail/system`, and `GET /api/v1/audit-trail/me` use **cursor pagination** (`cursorPaginate()` ordered by `id`) because audit tables are expected to exceed millions of rows per tenant over time.
- No full-list endpoints in this module.

### Caching

Per `coding-standards.md` § Caching:

- Do **not** cache cursor-paginated audit lists; append-only tables make stale pages confusing and data is compliance-sensitive.
- Tenant-prefixed cache key `{tenant_slug}:audit:event_types:all` caches the distinct `event_type` catalog (warm TTL 300s) for filter dropdowns, invalidated by new event type insertion.
- Tenant-prefixed cache key `{tenant_slug}:audit:entity_types:all` caches the distinct `entity_type` catalog (warm TTL 300s) for filter dropdowns.

### Rate Limiting

Per `coding-standards.md` § Rate Limiting and `App\Support\RateLimits`:

- All list endpoints: `RateLimits::LIST` (60/min per user)
- No mutating endpoints in this module (append-only)

### Database Transactions

Per `coding-standards.md` § Database Transactions:

- Audit row insertion is a single write wrapped inside the originating event's transaction boundary via `ShouldDispatchAfterCommit`; no separate transaction needed in the listener.
- If batch insertion is implemented for high-volume events, wrap the batch in `DB::transaction()`.

### Error Handling & Logging

Per `coding-standards.md` § Error Handling & Logging:

- Module logging channel: `audit` (add to `config/logging.php`, daily, 30-day retention)
- Listener uses try/catch with `Log::channel('audit')` and structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `event_type`, `performed_by`
- Never throw from the listener in a way that breaks the originating business transaction; log and continue

### Enums

Per `coding-standards.md` § Enum Usage:

- Create `App\Modules\Audit\Enums\AuditEntityType` (int-backed) for known entity categories: `Task = 1`, `StageInstance = 2`, `SubStageInstance = 3`, `User = 4`, `Position = 5`, `Department = 6`, `Blueprint = 7`, `Document = 8`, `Escalation = 9`, `SlaTimerInstance = 10`, `FollowUpAction = 11`, `Comment = 12`, `HelpArticle = 13`, `OnboardingJourney = 14`, `Tenant = 15`, `PlatformAdmin = 16`, `Impersonation = 17`, `WorkingCalendar = 18`, `PublicHoliday = 19`, `AuthorityGrade = 20`, `PositionAssignment = 21`, `Delegation = 22`, `MonitoringScopeGrant = 23`, `AuditGrant = 24`, `CapabilityGrant = 25`, `StageType = 26`, `SlaPolicy = 27`, `BlueprintCategory = 28`, `BlueprintStage = 29`, `BlueprintSubStage = 30`, `BlueprintTransition = 31`
- Use enum in service mapping logic; `event_type` remains string to stay flexible across modules

### Queue Jobs

Per `coding-standards.md` § Queues & Jobs:

- Audit event recording is synchronous within the event listener (events already implement `ShouldDispatchAfterCommit`, so the listener runs after the originating transaction commits)
- No standalone queued jobs in MVP
- V2 may introduce queued batch export job for audit log export

---

## Out of Scope

- Audit log export to file (feature #202) — V2
- User activity report/dashboard (feature #203) — V2; MVP provides raw API only
- Audit delegation history detail view (feature #210) — V2
- Modification or deletion of audit rows by any user or admin
- Central/platform tenant audit events — already implemented in Spec 001
- Audit grant CRUD — already implemented in IAM Spec 003
- Real-time audit streaming / SIEM integration — V3
- Automated anomaly detection on audit patterns — V3
- Long-term cold storage / archive of audit events — V2

---

## Open Questions

- [x] **Synchronous or queued listener?** Should audit event recording be synchronous or queued? **Resolution:** Synchronous for MVP. Events use `ShouldDispatchAfterCommit` and the audit write is a single lightweight insert. Queue deferred to V2 if load testing shows impact.
- [x] **Payload content?** What should the audit payload contain? **Resolution:** Event-specific metadata only. Each event's `auditData()` returns a bounded payload. No queries back to other modules.
- [x] **Hide IP/UA from non-admins?** Should IP and user agent be hidden from non-admin viewers? **Resolution:** `audit.view_system` includes IP/UA; `my-activity` endpoint omits them for privacy.
- [x] **Events before migration?** Should historical events before the audit module was deployed be backfilled? **Resolution:** Not backfilled. Audit trail starts from module deployment.
- [x] **Centralized or split listeners?** Should each event type have its own listener or a single centralized listener? **Resolution:** Centralized via interface. Each event implements `ProvidesAuditData` with an `auditData()` method. A single `RecordAuditEvent` listener (~60 lines) checks the interface and persists. No mapper registry.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
