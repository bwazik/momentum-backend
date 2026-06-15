# Spec: Search & Discovery

> **Number:** 011
> **Date:** 2026-06-14
> **Updated:** 2026-06-15
> **Status:** `stable`
> **Milestone:** M6 — Analytics, Follow-up & Search
> **Depends on:** `005-task-execution` (tasks, stage instances, `TaskVisibilityScope`), `006-stage-lifecycle` (stage history, completion notes), `003-iam-abac` (users, ABAC policy engine, capabilities), `002-organization-structure` (departments, positions), `010-follow-up-board` (confirms board provides only ILIKE title filter; this spec provides proper full-text search), `013-comments-collaboration` (comment content must be indexed — dependency noted; MVP search can exclude comments until 013 is implemented), `014-external-references` (external reference lookup — dependency noted; MVP search includes reference number search once 014 is implemented)
> **Provides APIs:** `GET /api/v1/search` (unified full-text task search), `GET /api/v1/search/tasks` (task search with filters), `GET /api/v1/search/recent` (recent activity — last 20 items viewed or actioned by the caller)
> **Contract status:** `stable`
> **Frontend spec:** `—` (backend-only for MVP; frontend consumes search endpoint and recent activity)
> **Author:** Momentum init
> **Branch:** `feat/011-search-discovery`
> **Base branch:** `main`

---

## Problem

Specs 001–010 have built a complete task management platform: tasks are created, assigned, progressed through stages, tracked for SLA health, and reported on analytically. But users have no efficient way to find a specific task or group of tasks across the platform without already knowing exactly where to look.

Currently, the only text-based filter is the board's `search` parameter in Spec 010, which performs a simple `ILIKE` substring match on `title_ar` and `title_en`. This is insufficient because:

1. **Users can't search inside task content.** A task titled "Director Response" with a description mentioning "budget ceiling 2026" is undiscoverable unless the user browses to it by status/department.
2. **External reference lookup is scattered.** A follow-up specialist looking for all tasks linked to reference number `وارد-2026-00412` must know to use the board's external reference filter. There is no dedicated, discoverable search surface for this.
3. **Stage completion notes are invisible.** Valuable context recorded by assignees (e.g., "legal opinion: object to procurement clause") is locked inside individual stage instances, unsearchable from any list view.
4. **There is no recent activity.** A user returning to the platform after two hours must manually navigate back to every task they were working on. There is no "last 20 items I touched" shortcut.
5. **Arabic full-text search requires proper tokenization.** A simple ILIKE match on Arabic text fails to handle morphological variants, prefixes, and suffixes common in Arabic (`التواصل` vs `تواصل`). PostgreSQL FTS with the correct text search configuration is the correct solution.

The Search module (defined in `03_Module_Boundary_Map.md` as an Infrastructure Layer bounded context) is the platform's answer to these gaps. It provides a unified discovery surface backed by PostgreSQL full-text search that is ABAC-aware, bilingual (Arabic + English), and tenant-isolated.

---

## Goal

Deliver a Search module (`app/Modules/Search/`) that exposes:

1. **Full-text task search** — query task titles, descriptions, and stage completion notes using PostgreSQL tsvector full-text search with bilingual (`simple` config for Arabic + `english` config for English) support. Results are ABAC-filtered via `TaskVisibilityScope` and respect confidentiality rules.
2. **External reference search** — find all tasks linked to a specific reference number (exact match); depends on `014-external-references` being in the tenant DB.
3. **Structured filters on search results** — filter by status, priority, date range, department, or Blueprint, composable with the full-text query.
4. **Recent activity feed** — return the last 20 task interactions (viewed, stage completed, stage returned, comment added) for the authenticated user, drawn from a lightweight `user_recent_activity` log maintained by the Search module.

The Search module is **read-only** with respect to all business modules. It may maintain its own `user_recent_activity` table in the tenant DB. It does not write to Task, Tracking, IAM, or Organization tables. ABAC visibility is delegated to `TaskVisibilityScope` exactly as the Follow-Up and Analytics modules do it.

---

## User Stories

### Full-Text Search

- As an **internal user**, I want to type a phrase into a search bar and get a ranked list of matching tasks (by title, description, or stage notes), so that I can find any task without knowing exactly where it is in the system.
- As a **follow-up specialist**, I want to search in both Arabic and English simultaneously, so that tasks with mixed-language content are always discoverable regardless of which language I type in.
- As a **department director**, I want search results to respect my ABAC visibility scope (org-wide, department-touched, or follow-up monitoring), so that I never see tasks I am not authorized to view.
- As an **internal user**, I want to search for tasks that contain confidential content I am authorized to access, and NOT see confidential tasks I am not a named participant on, so that confidentiality rules are enforced uniformly across the platform.

### External Reference Search

- As a **follow-up specialist**, I want to enter a correspondence number, contract number, or authority reference number and instantly see all tasks linked to it, so that I can track all work related to a specific external document.
- As an **internal user**, I want external reference lookup to be exact-match (not fuzzy), so that I do not get false positives when reference numbers follow structured patterns (e.g., `وارد-2026-00412`).

### Filtered Search

- As an **internal user**, I want to filter search results by task status (active, suspended, completed, cancelled), so that I can narrow results to open or closed work.
- As an **internal user**, I want to filter search results by priority (Routine, Urgent, Critical), so that I can focus on high-priority results.
- As an **internal user**, I want to filter search results by date range (created or completed within a period), so that I can limit results to a relevant timeframe.
- As an **internal user**, I want to filter search results by department (the department that currently owns the active stage), so that I can scope results to my area.
- As an **internal user**, I want to filter search results by Blueprint, so that I can limit results to a specific workflow type.

### Recent Activity

- As an **internal user**, I want to see the last 20 tasks I interacted with (viewed, completed a stage, returned a stage, or added a comment), so that I can quickly resume work without navigating back through the platform.
- As an **internal user**, I want the recent activity list to exclude tasks that have been deleted (cancelled tasks remain visible), so that my history is accurate.

### System

- As the **system**, I want search results to exclude `draft` tasks, so that only launched work is discoverable.
- As the **system**, I want all search queries to be scoped to the tenant's own data via the existing tenant connection — never crossing into another tenant's DB.
- As the **system**, I want recent activity to be user-specific and tenant-scoped, so that one user's activity never appears in another user's feed.

---

## Acceptance Criteria

### Full-Text Search Endpoint

- [x] `GET /api/v1/search/tasks` — returns a cursor-paginated list of tasks matching the full-text query, enriched with: `public_id`, `title_ar`, `title_en`, `status`, `priority`, `classification_level`, `current_stage` (name, type), `department` (name of active stage owning dept), `blueprint_category`, `due_date`, `created_at`, `snippet_ar` (highlighted match excerpt from Arabic content, if any), `snippet_en` (highlighted match excerpt from English content, if any).
- [x] `q` parameter: required for full-text search; minimum 2 characters; maximum 200 characters. If omitted or empty, endpoint returns 422.
- [x] Full-text search uses PostgreSQL `tsvector` / `to_tsquery()`. Two search vectors per task: `search_vector_ar` (config: `simple`, covers `title_ar` + `description_ar` + aggregated `completion_note_ar` from stage assignments) and `search_vector_en` (config: `english`, covers `title_en` + `description_en` + aggregated `completion_note_en`).
- [x] Results ranked by `ts_rank` descending (most relevant first). When ranks are equal, secondary sort by `tasks.id` descending (most recent first) for stable cursor pagination.
- [x] Endpoint enforces `TaskVisibilityScope` — users see only tasks within their ABAC visibility grant (org-wide, department-touched, follow-up scope, own participation).
- [x] Confidential tasks: visible only to named participants and override-capable users, exactly as in Task and FollowUp modules.
- [x] Draft tasks excluded (`status != draft`).
- [x] The `external_reference` filter parameter: accepts a reference number string (exact match on `task_external_references.reference_number`). If the `task_external_references` table does not exist (014 not yet implemented), returns 422 with `"External reference search is not yet available."`. Once 014 is live, this filter is fully functional.

### Structured Filters on Task Search

- [x] `status` filter: accepts one or more of `active`, `suspended`, `completed`, `cancelled`.
- [x] `priority` filter: accepts one or more `priority_public_id` values.
- [x] `date_from` / `date_to` filters: date range on `tasks.created_at` by default; optional `date_field` parameter selects `created_at` or `completed_at`.
- [x] `department` filter: accepts a `department_public_id`; filters tasks whose currently active stage has that department as the owning department.
- [x] `blueprint` filter: accepts a `blueprint_public_id`.
- [x] `blueprint_category` filter: accepts a `blueprint_category_public_id`.
- [x] All filters compose with the `q` full-text query (AND logic). Invalid filter values return 422 with field-level messages.

### Search Index Maintenance

- [x] `tasks` table gains two generated columns: `search_vector_ar` (`tsvector`, generated from `title_ar` + `description_ar`) and `search_vector_en` (`tsvector`, generated from `title_en` + `description_en`), maintained by additive tenant migration.
- [x] A GIN index is created on `search_vector_ar` and `search_vector_en` for efficient full-text lookups.
- [x] Stage completion notes are NOT stored in a generated column on `tasks`. Instead, a separate `task_search_index` table maintains a consolidated text blob per task (updated via domain event listeners), holding denormalized `completion_notes_ar` and `completion_notes_en` from all stage assignments. This keeps the FTS query fast without joining across N stage records per search hit.
- [x] `task_search_index` table in tenant DB: `id` (BIGINT PK), `task_id` (FK to `tasks.id`, unique), `notes_ar` (text, nullable), `notes_en` (text, nullable), `search_vector_notes_ar` (tsvector, nullable), `search_vector_notes_en` (tsvector, nullable), `updated_at`. GIN indexes on both `search_vector_notes_ar` and `search_vector_notes_en`.
- [x] `SearchIndexListener` consumes `StageAssignmentCompleted` events (from Spec 006) to upsert `task_search_index` with the latest completion notes. The listener is idempotent and queued via `ShouldQueue`.
- [x] When `013-comments-collaboration` is implemented, the `task_search_index` listener can be extended to include comment content. This is out of scope for this spec.

### Recent Activity

- [x] `user_recent_activity` table in tenant DB: `id` (BIGINT PK), `user_id` (FK to `users.id`), `task_id` (FK to `tasks.id`), `activity_type` (enum: `TaskViewed`, `StageCompleted`, `StageReturned`, `CommentAdded`), `occurred_at` (timestamp). Composite index on `(user_id, occurred_at DESC)`. A unique constraint is NOT applied — the same task may appear multiple times from different activities.
- [x] `GET /api/v1/search/recent` — returns the last 20 distinct tasks the authenticated user interacted with, ordered by most recent interaction first. Deduplication: if the same `task_id` appears multiple times in `user_recent_activity`, only the most recent entry for that task appears in the result. Each entry includes: `public_id`, `title_ar`, `title_en`, `status`, `activity_type` (the most recent type), `occurred_at`.
- [x] ABAC filtering applied: tasks deleted (soft-deleted from `tasks`) are excluded. Cancelled tasks remain in the list (they are still accessible).
- [x] `user_recent_activity` rows are written by `SearchActivityListener` consuming: `TaskViewed`, `StageAssignmentCompleted`, `StageInstanceReturned` events. The listener is queued.
- [x] **`TaskViewed` event**: The Task module does not currently emit a view event. To track task views, the Search module's `TaskController::show()` endpoint (or a middleware hook) must emit a `TaskViewed` event after a successful task show request. This requires a lightweight `TaskViewed` domain event to be added. **Resolved:** `TaskService::findVisible()` emits `TaskViewed` after successful visibility authorization.
- [x] `user_recent_activity` is user-specific: one user's feed is never returned for another user.
- [x] `user_recent_activity` rows older than 90 days are pruned by a scheduled `PruneRecentActivityCommand` running daily. This prevents unbounded table growth.

### Capabilities

- [x] No new capability required for basic search — any authenticated user with `task.view.*` visibility can search within their scope.
- [x] Attempting to search without any `task.view.*` capability returns an empty result set (not 403) — consistent with `TaskVisibilityScope` returning an empty query for users with no visibility grants.

### Response Shape

- [x] Task search endpoint returns cursor-paginated `{data, next_cursor, has_more}`.
- [x] Recent activity endpoint returns a bounded non-paginated `{data: [...]}` (max 20 items — not cursor-paginated, per the "last 20" specification in Feature Inventory #189).
- [x] All responses expose `public_id` only — never internal `id`.

### Tests

- [x] Feature tests cover: full-text search in Arabic, full-text search in English, combined Arabic+English match, `q` too short (422), status filter, priority filter, date range filter, department filter, blueprint filter, external reference filter with 014 present (exact match), external reference filter without 014 (422), ABAC filtering (org-wide vs. department-touched vs. no grants = empty), confidential task exclusion for non-participant, draft task exclusion, cursor pagination (ordered by rank then id), recent activity list for authenticated user (last 20, deduplication), recent activity isolation (user A's feed does not include user B's activity), `PruneRecentActivityCommand` removes rows older than 90 days.

---

## Non-Functional Requirements

### Pagination

- `GET /api/v1/search/tasks` uses **cursor pagination** (tasks can exceed 1000 rows per tenant). Results are ordered by `ts_rank DESC, tasks.id DESC`. Cursor must encode both rank and id for stable pagination. See `coding-standards.md` — Pagination Strategy.
- `GET /api/v1/search/recent` returns **full list** (hard limit: 20 items). No pagination. This is a fixed, user-specific, bounded set. See `coding-standards.md` — Exception: Small Stable Tables.

### Caching

- **Search results are NOT cached.** Query result staleness would mislead users who expect real-time discovery. PostgreSQL GIN indexes provide sub-50ms FTS performance at the tenant scale expected for MVP.
- **Recent activity is NOT cached.** It reflects the last 20 interactions and must be fresh.
- The `task_search_index` denormalized note content is maintained by event listener (not cached in Redis) — it is the persistent read model for note search, not a cache.
- All Redis cache keys (if any helpers are used) must be tenant-prefixed per `coding-standards.md` — Caching.

### Rate Limiting

- `GET /api/v1/search/tasks`: `RateLimits::LIST` (60/min per user).
- `GET /api/v1/search/recent`: `RateLimits::LIST` (60/min per user).
- No route-level throttle strings; controllers use the `HasRateLimiting` trait and `RateLimits` constants per `coding-standards.md` — Rate Limiting.

### Database Transactions

- `task_search_index` upsert (via `SearchIndexListener`) is a single write — no `DB::transaction()` required.
- `user_recent_activity` insert (via `SearchActivityListener`) is a single write — no `DB::transaction()` required.
- All search endpoints are read-only — no `DB::transaction()` required.
- See `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `search` (add to `config/logging.php` following the existing module channel pattern, e.g., `daily` driver, `storage_path('logs/search.log')`, 14-day retention).
- All service methods use try/catch with `Log::channel('search')`.
- Structured log context includes: `tenant_slug`, `action` (e.g., `search.tasks`, `search.recent`, `search.index_update`), `entity_type`, `entity_id`, `performed_by`.
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages.
- Expected domain exceptions: `SearchQueryTooShortException` (422 if `q` < 2 chars), `ExternalReferenceSearchNotAvailableException` (422 if `task_external_references` table absent).
- Error handling must follow `coding-standards.md` — Error Handling & Logging.

### Enums

- Create `SearchActivityType` enum in `app/Modules/Search/Enums/SearchActivityType.php`: `TaskViewed = 1`, `StageCompleted = 2`, `StageReturned = 3`, `CommentAdded = 4`. Int-backed, stored as TINYINT in `user_recent_activity.activity_type`.
- No other new enums required. Reuse existing `TaskStatus`, `ClassificationLevel`, `AssignmentRole` enums where needed.
- Form requests use `Rule::enum(...)` for any enum-validated parameters. Services use enum cases, never raw integers. See `coding-standards.md` — Enum Usage.

### Queue Jobs

- `SearchIndexListener` (consumes `StageAssignmentCompleted`) implements `ShouldQueue` with `$tries = 3`, `$backoff = [30, 60, 120]`. Failure is logged to the `search` channel and does not interrupt the main task flow.
- `SearchActivityListener` (consumes `StageAssignmentCompleted`, `StageInstanceReturned`) implements `ShouldQueue` with `$tries = 3`, `$backoff = [30, 60, 120]`.
- `PruneRecentActivityCommand` is a scheduled Artisan command dispatched by the Laravel scheduler; it does not use a queued job (it runs in the scheduler process).
- All domain events consumed by Search listeners implement `ShouldDispatchAfterCommit` (already enforced by Spec 006 for stage events).
- A new lightweight `TaskViewed` domain event will be emitted from the Task module's show endpoint (or via middleware). This event implements `ShouldDispatchAfterCommit`. Queue behavior must follow `coding-standards.md` — Queues & Jobs.

---

## Out of Scope

- **Advanced search with multiple criteria** (Feature #190) — V2. The current spec delivers single-query FTS + composable filters. Saved multi-field form builder is deferred.
- **Saved searches** (Feature #191) — V2. Requires a `saved_search_filters` table and user-preference management.
- **Hijri date search** (Feature #188) — Deferred to `018-localization-calendar`. Hijri-to-Gregorian conversion helpers are not yet implemented in Core; the search endpoint will accept only Gregorian dates for now. Once Spec 018 is complete, the date filter can be extended to accept Hijri input transparently.
- **Comment content indexing** — Deferred until `013-comments-collaboration` is implemented. The `task_search_index` table schema is designed to accommodate `comment_content_ar` / `comment_content_en` in a future additive migration; no data contract breakage.
- **External reference exact search** — functional only after `014-external-references` is implemented. The spec defines the API contract; the endpoint returns 422 with a clear message until Spec 014 lands.
- **Help Center article search** — The Search module blueprint (`03_Module_Boundary_Map.md`) states Help Center content will be indexed by Search in the future. This is deferred to `020-help-center`.
- **Real-time search-as-you-type / autocomplete** — MVP uses explicit submit; live suggestions require V2 endpoint work.
- **Modifying Task, Tracking, IAM, Blueprint, or Organization tables** — the Search module maintains only its own `task_search_index` and `user_recent_activity` tables in the tenant DB.
- **Global (cross-module) search** — search is scoped to tasks only in MVP. Blueprint search and user directory search are deferred.
- **Editing or deleting `user_recent_activity` entries** — append-only log (pruned automatically after 90 days by scheduler).

---

## Open Questions — Resolved

- [x] **Where should the `TaskViewed` event be emitted?** → **Decision:** Add `TaskService::findVisible()` and emit `TaskViewed` from it; call it from `TaskController::show()`. Keeps event emission in the service layer, reuses the existing visibility scope, and keeps the controller thin.
- [x] **Should `search_vector_ar` and `search_vector_en` be PostgreSQL generated columns or maintained via trigger/listener?** → **Decision:** Generated columns for title+description on `tasks`; listener-maintained `task_search_index` for notes. Generated columns are zero-code for static task fields; notes come from a related table so they need a denormalized read model.
- [x] **Should the `task_search_index` upsert happen synchronously or asynchronously?** → **Decision:** Asynchronous queued listener (`SearchIndexListener`). Stage completion must not block on index update; a few seconds of staleness is acceptable.
- [x] **Should recent activity deduplication happen at query time or write time?** → **Decision:** Insert every activity row; deduplicate at query time with `DISTINCT ON (task_id)` + `LIMIT 20`. Preserves accurate history, avoids complex upsert logic, and the 90-day prune bounds table growth.
- [x] **Should `TaskViewed` activity writes happen for every view call, or only within a session window?** → **Decision:** Deduplicate at write time — skip insert if the same user+task has a `TaskViewed` entry within the last 5 minutes. Prevents flooding from repeated page refreshes while still recording revisits after a reasonable gap.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
