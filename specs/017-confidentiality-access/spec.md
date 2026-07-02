# Spec: Confidentiality & Access

> **Number:** 017
> **Date:** 2026-07-02
> **Status:** `completed`
> **Milestone:** M2 — Organization & IAM
> **Depends on:** `003-iam-abac` (ABAC policy engine, capability catalog, scoped grants, position assignments), `005-task-execution` (`tasks` table, `classification_level`, `TaskVisibilityScope`, assignment tables, task launch), `006-stage-lifecycle` (stage/sub-stage history, current/past assignment records), `015-audit-trail` (immutable audit event recording)
> **Provides APIs:** Confidential participant management, confidential governance participant configuration, confidential metadata view, confidential content override, classification enforcement across task visibility
> **Contract status:** `stable`
> **Frontend spec:** `—`
> **Author:** OpenCode
> **Branch:** `feat/017-confidentiality-access`
> **Base branch:** `main`

---

## Problem

The platform currently enforces basic task visibility through the ABAC policy engine (Spec 003) and `TaskVisibilityScope` (Spec 005): users can see tasks they initiated, are assigned to, or are authorized to view through organization-wide, department-touched, or follow-up-scope capabilities. However, the `classification_level` column on `tasks` is stored but not meaningfully enforced.

Without this spec:

- **Confidential tasks leak to organization-wide viewers.** A user with `task.view.organization` can currently view a confidential task in full, breaking the need-to-know principle.
- **There is no named participant model.** Confidential tasks cannot be limited to explicitly named users beyond the initiator and assignees.
- **There is no governance participant configuration.** Tenants cannot automatically include mandatory oversight positions (e.g., Minister's Office, General Counsel, Internal Audit) on confidential tasks.
- **There is no redacted metadata view.** Senior leaders who need to know a confidential task exists cannot see a limited, safe summary without viewing full content.
- **There is no audited override path.** Governance officers cannot open confidential content in an emergency or investigation without a silent bypass.
- **Confidential access is invisible to audit.** There is no dedicated record of who viewed metadata, exercised override, or managed confidential participants.

This leaves the platform unable to handle sensitive government, legal, HR, whistleblower, or investigation workflows where confidentiality is a core requirement.

---

## Goal

Implement the classification-based access model defined in `../_blueprints/04_Visibility_Access_Rules.md` so that `public`, `internal`, and `confidential` classification levels are enforced consistently across task viewing, listing, search, analytics, follow-up, comments, documents, and stage history.

After this spec:

- `public` tasks follow existing ABAC visibility rules without extra restriction.
- `internal` tasks block lateral uninvolved department visibility while remaining accessible to participants, department-touched viewers, follow-up viewers, and organization-wide viewers within scope.
- `confidential` tasks are visible only to the task initiator, current/past stage or sub-stage assignees, explicitly named confidential participants, configured confidential governance participants, technical tenant admins, and users exercising an audited override.
- Authorized users can manage named confidential participants and configure automatic governance participants.
- Users with `task.confidential.view_metadata` can retrieve a redacted metadata summary of a confidential task.
- Users with `task.confidential.view_override` can open full confidential content after supplying a mandatory reason; every override is recorded as an immutable audit event.
- All confidential access events (metadata view, content override, participant added/removed) are persisted in a dedicated table and emitted to the Audit module.

All changes stay within tenant DB tables and follow existing module boundaries: Task module owns task-level visibility and participant tables; IAM module owns governance participant configuration and capability checks; Audit module consumes events.

---

## User Stories

### Classification Enforcement

- As an **internal user without confidentiality access**, I want confidential tasks to be hidden from my task board, search results, and dashboards, so that I only see information I am authorized to access.
- As a **user with organization-wide visibility**, I want confidential tasks excluded from my lists unless I am a named participant or governance viewer, so that need-to-know restrictions are preserved.

### Confidential Task Creation & Management

- As a **task initiator with `task.classify.confidential`**, I want to mark a task as confidential at creation or later, so that the task is restricted to named participants and governance roles.
- As a **task initiator or authorized manager**, I want to add or remove named confidential participants on a confidential task, so that the right people can access sensitive work.
- As a **tenant admin**, I want to configure positions that are automatically included as governance participants on confidential tasks (by department, blueprint category, or tenant-wide), so that accountable oversight roles are never accidentally excluded.

### Metadata & Override Access

- As a **senior leader or governance officer with `task.confidential.view_metadata`**, I want to see a limited metadata summary (title or redacted title, owning department, status, responsible position, SLA health) of confidential tasks, so that I can monitor accountability without viewing sensitive content.
- As a **governance officer with `task.confidential.view_override`**, I want to open the full content of a confidential task after entering a mandatory reason, so that I can perform my oversight duties during investigations or emergencies.
- As a **compliance officer**, I want a searchable log of every metadata view, content override, and participant change on confidential tasks, so that I can review governance access.

---

## Acceptance Criteria

### Data Model

Per `../_blueprints/07_Database_Documentation.md` Section 4, the confidentiality tables are intentionally lightweight:

- [x] `task_confidential_participants` table in tenant DB includes: `id`, `task_id` (FK tasks), `user_id` (FK users), `added_by_user_id` (FK users), `added_at` (timestamp), `removed_at` (nullable timestamp). No `public_id`, `tenant_id`, or soft-delete columns. `removed_at` preserves removal history while keeping the blueprint's core columns intact.
- [x] `confidential_governance_participants` table in tenant DB includes: `id`, `public_id` (UUID v7), `position_id` (FK positions), `scope_type` (TINYINT, reuses `ScopeType`), `scope_department_id` (nullable FK departments), `blueprint_category_id` (nullable FK blueprint_categories), `applies_to_classification_level` (TINYINT, reuses `ClassificationLevel`, default `3 = confidential`), `created_by_user_id` (FK users), `created_at`, `revoked_at` (nullable timestamp). No `tenant_id`. `public_id` is added only to this table so update/revoke endpoints follow the project's URL convention.
- [x] `confidential_access_events` table in tenant DB includes: `id`, `task_id` (FK tasks), `user_id` (FK users), `access_type` (TINYINT), `reason` (nullable text), `created_at`. No `public_id`, `tenant_id`, or soft-delete columns.
- [x] Models extend `TenantModel` (or appropriate base) and contain no `tenant_id` column. Junction and audit tables omit `public_id`; the governance config table uses `public_id` for route model binding.

### Capabilities

- [x] Reuse existing capabilities seeded in Spec 003:
  - `task.classify.confidential` — create or change a task to confidential
  - `task.confidential.view_metadata` — view redacted metadata of confidential tasks
  - `task.confidential.view_override` — open full confidential content with audited reason
  - `task.confidential.manage_participants` — add or remove named confidential participants
- [x] No new capability keys are required; existing keys are enforced through `RequireCapability` and `IamPolicy::check()`

### Confidential Participant Management

- [x] `POST /api/v1/tasks/{task}/confidential-participants` — add a user as a named confidential participant. Request body: `user_id` (public_id of the user to add). Task must have `classification_level = confidential`. Requires `task.confidential.manage_participants` scoped to the task's department, **or** the user is the task initiator and tenant policy allows initiator-managed participants.
- [x] `DELETE /api/v1/tasks/{task}/confidential-participants/{user}` — remove a named participant by the participant's `public_id`. Same authorization rules as create.
- [x] `GET /api/v1/tasks/{task}/confidential-participants` — list named participants with user summary. Requires visibility to the parent task.
- [x] Adding or removing a participant writes a `confidential_access_events` row with `access_type = participant_added` or `participant_removed` and emits a domain event implementing `ShouldDispatchAfterCommit` + `ProvidesAuditData`.
- [x] Duplicate active participant entries for the same `(task_id, user_id)` are rejected with 422.
- [x] Removing a participant soft-deletes the row (or sets a `removed_at` timestamp) to preserve history.

### Confidential Governance Participant Configuration

- [x] `POST /api/v1/iam/confidential-governance-participants` — configure a position as an automatic governance participant. Requires `iam.manage_capabilities`.
- [x] `GET /api/v1/iam/confidential-governance-participants` — list governance participant configurations. Requires `iam.manage_capabilities`.
- [x] `PUT /api/v1/iam/confidential-governance-participants/{participant}` — update scope or classification applicability. Requires `iam.manage_capabilities`.
- [x] `POST /api/v1/iam/confidential-governance-participants/{participant}/revoke` — set `revoked_at = now()`. Requires `iam.manage_capabilities`.
- [x] Validation enforces the subset allowed by `../_blueprints/07_Database_Documentation.md` Section 4.30:
  - `scope_type = tenant` (`1`) → no `scope_department_id`
  - `scope_type = specific_department` (`3`) / `department_tree` (`4`) → `scope_department_id` required
  - `own_department` (`2`) and `own_tasks` (`5`) are **not** valid for governance participant configurations
  - `blueprint_category_id` is optional for all scope types; when provided, access is further restricted to tasks whose blueprint belongs to that category
- [x] Governance participants apply only to tasks whose classification level matches `applies_to_classification_level` (default `3 = confidential` for MVP).
- [x] The current occupant of a governance position at the time of task access resolution is considered a governance viewer; if the position is vacant, no access is granted through that configuration.

### Confidential Metadata View

- [x] `GET /api/v1/tasks/{task}/metadata` — returns a redacted metadata summary when:
  - the task is `confidential`,
  - the caller has `task.confidential.view_metadata` capability scoped to the task's department/scope,
  - the caller does **not** already have normal full visibility to the task.
- [x] Response includes: `public_id`, classification level, title or redacted title (per tenant policy), owning department, current responsible position, current status, due date / SLA health, and a flag indicating metadata-only access.
- [x] Access is recorded in `confidential_access_events` with `access_type = metadata_view` and emitted as an audit event.
- [x] If the caller already has full visibility, the endpoint returns 404 to keep the endpoint purpose-specific; callers should use `GET /api/v1/tasks/{task}` instead.

### Confidential Content Override

- [x] `POST /api/v1/tasks/{task}/access-override` — allows a user with `task.confidential.view_override` capability to open the full task content.
- [x] Request body requires `reason` (text, min length). Optional `expires_at` may be accepted but is not enforced in MVP.
- [x] The endpoint validates capability scope covers the task's department/scope.
- [x] On success, the response returns the same payload as `GET /api/v1/tasks/{task}` (via `TaskResource`) and records a `confidential_access_events` row with `access_type = content_override`.
- [x] Override access is evaluated per request; a successful override does not permanently add the user to the task.
- [x] The override event is emitted to Audit with `ProvidesAuditData`, including the reason and the capability grant used.
- [x] If the task is not `confidential`, the endpoint returns 422.

### Visibility Enforcement

- [x] `TaskVisibilityScope` (or equivalent service used by Task, Search, Analytics, FollowUp, Comment, Document modules) is updated to apply classification rules **after** normal ABAC rules:
  - `public` → no additional restriction.
  - `internal` → denies if the user has only lateral visibility, is not a participant, and the task has not touched the user's allowed department/scope.
  - `confidential` → denies unless the user is:
    - a technical tenant admin,
    - the task initiator,
    - a current or past stage/sub-stage assignee,
    - a named confidential participant,
    - the current occupant of a configured confidential governance participant position covering the task,
    - an external auditor with a valid audit grant covering the task (completed/archived only),
    - exercising a valid audited override.
- [x] Organization-wide capability `task.view.organization` does **not** bypass confidential restrictions.
- [x] Department-touched and follow-up-scope capabilities do **not** bypass confidential restrictions.
- [x] Search (`011`), Analytics (`009`), Follow-up Board (`010`), Comments (`013`), Documents (`012`), and Stage History (`006`) all reuse the updated visibility scope; no module implements its own confidentiality logic.
- [x] The visibility query remains performant for large task tables by filtering classification checks at the database layer where possible.

### Audit & Events

- [x] New domain events implementing `ShouldDispatchAfterCommit` + `ProvidesAuditData`:
  - `ConfidentialParticipantAdded`
  - `ConfidentialParticipantRemoved`
  - `ConfidentialMetadataViewed`
  - `ConfidentialContentOverridden`
- [x] `confidential_access_events` rows are written idempotently; viewing metadata multiple times creates multiple rows only if desired (default: one row per distinct request).
- [x] Audit events include: task public_id, user public_id, access type, reason (where applicable), timestamp, IP, user agent.

### General

- [x] All endpoints use `/api/v1/tasks/` or `/api/v1/iam/` prefix consistently with existing modules.
- [x] All API responses expose `public_id` only — never internal `id`.
- [x] All mutating endpoints require authentication and ABAC enforcement.
- [x] Feature tests cover: classification enforcement on task list/show, confidential participant CRUD, governance participant CRUD, metadata view, content override with reason, audit event recording, ABAC denials, tenant isolation, and cross-module visibility (search/comments/documents).

---

## Non-Functional Requirements

> These requirements follow `docs/ai/coding-standards.md`. Read that file before creating `plan.md`.

### Pagination

- `GET /api/v1/tasks/{task}/confidential-participants` uses **cursor pagination** (large tasks may have many participants). See `coding-standards.md` — Pagination Strategy.
- `GET /api/v1/iam/confidential-governance-participants` uses **cursor pagination** (expected > 1000 rows per tenant in large organizations).
- `GET /api/v1/tasks/{task}/confidential-access-events` uses **cursor pagination**.
- Governance configuration dropdown lookups for positions and departments return **full list** or single-record lookups as appropriate (small reference tables). See `coding-standards.md` — Exception: Small Stable Tables.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- Governance participant configuration is cached at `{tenant_slug}:iam:confidential_governance_participants:all` with TTL 300s (warm tier). Invalidated on any create/update/revoke event.
- Per-user effective confidential access for the current request is **not** cached across requests; it is re-evaluated per query to reflect participant and assignment changes.
- `IamPolicy` per-request memory cache continues to cache capability lookups (60s hot tier) per `coding-standards.md`.
- All cache keys are tenant-prefixed per `coding-standards.md` — Caching (Redis / phpredis).

### Rate Limiting

- Mutating endpoints (participant add/remove, governance config create/update/revoke, override request): `RateLimits::MUTATE` (30/min per user).
- List endpoints (participants, governance config, access events, metadata view): `RateLimits::LIST` (60/min per user).
- See `coding-standards.md` — Rate Limiting for `HasRateLimiting` trait usage.

### Database Transactions

- Adding or removing a confidential participant: `DB::transaction()` required — writes participant row + `confidential_access_events` row.
- Creating or updating governance participant configuration: `DB::transaction()` required — writes config row + invalidates cache inside transaction or via after-commit event.
- Confidential content override: `DB::transaction()` required — reads capability, writes access event, returns task data.
- Metadata view: single read + single write to access events; wrap in `DB::transaction()` for consistency.
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Task-side logging channel: `task` (participant and override operations).
- IAM-side logging channel: `iam` (governance participant configuration).
- All service methods use try/catch with the module-specific channel.
- Structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`.
- New domain exceptions (all extend `DomainException`, automatically rendered by the base handler at `bootstrap/app.php`):
  - `TaskNotConfidentialException` — operation requires a confidential task (422)
  - `CannotManageConfidentialParticipantsException` — user lacks participant management right (422)
  - `ConfidentialAccessDeniedException` — user cannot view or override confidential content (403)
  - `DuplicateConfidentialParticipantException` — user is already a named participant (422)
  - `InvalidGovernanceScopeException` — invalid scope field combination (422) in Iam module
  - `GovernanceParticipantNotFoundException` — participant record not found (404)
- See `coding-standards.md` — Error Handling & Logging.

### Enums

- Reuse existing `App\Modules\Task\Enums\ClassificationLevel` (`Public = 1`, `Internal = 2`, `Confidential = 3`) — do not duplicate.
- Reuse existing `App\Enums\ScopeType` for governance participant scope — do not duplicate.
- Create `App\Modules\Task\Enums\ConfidentialAccessEventType`:
  - `MetadataView = 1`
  - `ContentOverride = 2`
  - `ParticipantAdded = 3`
  - `ParticipantRemoved = 4`
- Use enum classes in form requests via `Rule::enum(ClassName::class)` and in service logic — never raw integers.
- See `coding-standards.md` — Enum Usage.

### Queue Jobs

- No queue jobs required for this spec — all operations are synchronous CRUD, visibility filtering, and audit event writes.
- All domain events implement `ShouldDispatchAfterCommit` — Audit module (Spec 015) consumes them synchronously or queues as needed.
- See `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **Document-level access restrictions** — deferred to V2 (Feature Inventory #182). Documents attached to a visible task remain visible to all task viewers.
- **Internal department-only comments** — deferred to V2 (Feature Inventory #174). All comments on a visible task remain visible to all task viewers.
- **Time-limited override windows** — optional `expires_at` may be accepted on override requests but is not enforced in MVP.
- **Automatic notifications on override** — Audit records the event; notifications to compliance or task owners are deferred.
- **Renaming classification labels** — tenant-configurable labels for Public/Internal/Confidential belong to a system-administration settings spec.
- **Task archive / reopen** — archive logic is deferred; external auditor visibility uses existing completed/archived status checks.
- **Cross-tenant confidentiality rules** — confidentiality is always evaluated within a single tenant.
- **Performance optimization beyond index-backed queries** — materialized visibility views or advanced caching strategies are deferred.

---

## Open Questions

- [x] **Initiator-managed participants:** Should the task initiator be able to add/remove participants without `task.confidential.manage_participants`? **Resolution:** Yes, by default. Tenant setting `settings.confidentiality.initiator_can_manage_participants` defaults to `true`; when disabled, falls through to capability check.
- [x] **Metadata title display:** Should metadata show the actual title or a redacted placeholder? **Resolution:** Tenant policy decides; default redacted. `__('task.confidential_redacted_title')` is the safe default. Tenant setting `settings.confidentiality.metadata_show_actual_title` can override.
- [x] **Governance participants on existing tasks:** Do governance configs apply only to new tasks or retroactively? **Resolution:** Retroactively. Governance participation is evaluated at access-time, not task-creation-time.
- [x] **Override persistence:** Does an override last for a session, time window, or single request? **Resolution:** Single request in MVP. Reason must be re-supplied per access. `expires_at` accepted but ignored.
- [x] **Metadata view for non-confidential tasks:** Should the metadata endpoint return data for non-confidential tasks? **Resolution:** Returns 404. Users with normal visibility should use `GET /api/v1/tasks/{task}`.
- [x] **Participant removal history:** Should participant removal hard-delete or preserve history? **Resolution:** Add `removed_at` timestamp. Preserves history while keeping core columns intact.
- [x] **Governance config public identifier:** Should `confidential_governance_participants` have a `public_id`? **Resolution:** Yes, only on this table. Enables standard `/api/v1/iam/confidential-governance-participants/{public_id}` URL convention. Junction and audit tables remain without `public_id`.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
