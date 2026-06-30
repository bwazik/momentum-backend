# Spec: Comments & Collaboration

> **Number:** 013
> **Date:** 2026-07-01
> **Status:** `completed`
> **Milestone:** M4 — Task Execution & Lifecycle
> **Depends on:** `005-task-execution` (tasks, task visibility, `TaskVisibilityScope`), `006-stage-lifecycle` (stage lifecycle events consumed by Search / Audit), `012-documents-attachments` (polymorphic `documents` table with `DocumentEntityType::Comment`; comment-attachment endpoints deferred), `003-iam-abac` (users, ABAC policy engine), `011-search-discovery` (`task_search_index` extension for comment content, `SearchActivityType::CommentAdded`)
> **Provides APIs:** `POST /api/v1/tasks/{task}/comments`, `GET /api/v1/tasks/{task}/comments`, `POST /api/v1/comments/{comment}/documents`, `GET /api/v1/comments/{comment}/documents`, domain event `CommentCreated`
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/003-task-details`
> **Author:** OpenCode
> **Branch:** `feat/013-comments-collaboration`
> **Base branch:** `main`

---

## Problem

Tasks in Gov TMS rarely exist in isolation. An initiator may need to clarify a directive, a stage assignee may need to ask for missing documents, and reviewers may need to record informal observations before a formal stage completion. Today the platform has no in-task conversation layer, so users fall back to email, chat, or phone calls. That fragments context, hides decisions from the audit trail, and makes it impossible to know *why* a task was handled a certain way without leaving the application.

Additionally, Spec 012 built a Document module that can attach files to tasks and stage outputs, but the `Comment` entity it references does not yet exist. The deferred comment-attachment endpoints (`POST /api/v1/comments/{comment}/documents`, `GET /api/v1/comments/{comment}/documents`) cannot be activated until Comment records are available.

Without this spec:

- **Collaboration is external** — task participants cannot communicate inside the task context.
- **Audit is incomplete** — informal clarifications and supporting evidence shared in side channels are never recorded.
- **Search (Spec 011)** cannot index comment text, so full-text search misses a large body of task-related content.
- **Document attachments (Spec 012)** cannot be attached to comments.

---

## Goal

Deliver a lightweight Comments subsystem inside the Task module. Authorized task participants can add top-level comments and single-level replies, attach documents to comments via the existing Document module, and view the full chronological comment history of a task. The subsystem emits a `CommentCreated` domain event so that Search can index comment text, Audit can record the action, and the existing recent-activity pipeline can surface `CommentAdded` entries.

After this spec:

- Any user who can view a task can read and add comments on it.
- Comment attachments reuse the Document module's polymorphic storage.
- Comment content is discoverable through the existing full-text search infrastructure.
- Comments are part of the immutable audit trail without any module writing to Audit tables directly.

---

## User Stories

### Task Participants

- As a **task initiator**, I want to add a comment to a task, so that I can provide clarification or additional context to assignees.
- As a **stage assignee**, I want to reply to an existing comment, so that follow-up questions and answers stay threaded inside the task.
- As a **task viewer**, I want to see all comments and replies in chronological order, so that I can understand the full conversation history before acting.
- As a **commenter**, I want to attach a file to my comment, so that I can share evidence or supporting documents without leaving the conversation.

### System & Downstream Modules

- As the **Search module**, I want to know when a comment is created and what text it contains, so that I can include comment content in full-text task search results.
- As the **Audit module**, I want to receive a domain event for every comment, so that the audit trail records who said what and when.
- As the **Notification module**, I want a hook for future comment notifications (V2), so that mention-based alerts can be added later without redesigning the comment model.

---

## Acceptance Criteria

### `comments` Table

- [x] `comments` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `task_id` (FK to `tasks.id`), `user_id` (FK to `users.id`, author), `parent_comment_id` (nullable FK to `comments.id`), `body` (TEXT), `created_at`, `updated_at`, `deleted_at`
- [x] `parent_comment_id` supports **single-level nesting** only: a top-level comment has `parent_comment_id = null`; a reply references a top-level comment; replies cannot have their own replies
- [x] Model extends `TenantModel`, uses `public_id` for route model binding; no `tenant_id` column
- [x] Model relationships: `task()`, `user()` (author), `parent()`, `replies()`
- [x] Soft deletes only — no hard delete endpoint in MVP

### Authorization

- [x] Creating or listing comments requires the caller to be able to view the parent task per `TaskVisibilityScope`
- [x] No separate capability is required to create or view comments; task visibility is the authorization gate
- [x] Comment attachments follow Spec 012 authorization: `task.manage_documents` to upload, `task.view_documents` + task visibility to list/download/preview
- [x] Confidential tasks: comments are visible only to users who can view the confidential task (named participants or override-capable users)

### Comment CRUD

- [x] `POST /api/v1/tasks/{task}/comments` — create a top-level comment or a reply. Request body: `body` (required, string, max 5000), `parent_comment_id` (optional, `public_id` of an existing top-level comment on the same task). Returns `CommentResource`.
- [x] When `parent_comment_id` is provided, the parent must belong to the same task and must itself be a top-level comment (`parent_comment_id` is null); otherwise return 422
- [x] `GET /api/v1/tasks/{task}/comments` — list top-level comments for the task. Each top-level comment includes its nested replies (full list, ordered chronologically). Includes author `public_id` and name, `body`, `created_at`, `attachment_count`.
- [x] All comment responses expose `public_id` only — never internal `id`
- [x] Editing or deleting comments is not supported in MVP

### Comment Attachments

- [x] `POST /api/v1/comments/{comment}/documents` — activate the endpoint deferred in Spec 012; attach a file to the comment using `DocumentEntityType::Comment`
- [x] `GET /api/v1/comments/{comment}/documents` — list current-version documents attached to the comment
- [x] Comment attachment upload/list reuse the existing Document service, controllers, and API resources from Spec 012
- [x] Upload validation (MIME type, max size) follows Spec 012 decisions

### Search & Recent Activity Integration

- [x] `CommentCreated` domain event implements `ShouldDispatchAfterCommit` and carries the comment, author, and task
- [x] Additive migration on `task_search_index` (from Spec 011) adds `comment_content_ar` and `comment_content_en` text columns plus corresponding `tsvector` generated columns and GIN indexes
- [x] `SearchIndexListener` is updated to consume `CommentCreated` and upsert comment text into `task_search_index`
- [x] `SearchActivityListener` is updated to consume `CommentCreated` and write a `SearchActivityType::CommentAdded` row to `user_recent_activity`
- [x] `CommentCreated` event implements `ProvidesAuditData` so the Audit module records it automatically

### Events & Audit

- [x] Domain event `CommentCreated` implements `ShouldDispatchAfterCommit`
- [x] Audit record includes: task `public_id`, comment `public_id`, author `public_id`, timestamp, and a summary of the action
- [x] No direct writes to `audit_events` from the Task module

### Tests

- [x] Feature tests cover: create top-level comment, create reply, reject reply-to-reply, reject reply to comment on another task, list comments with nested replies, ABAC deny when user cannot view task, confidential task comment visibility, comment attachment upload/list, search index updated after comment creation, recent activity includes `CommentAdded`

---

## Non-Functional Requirements

### Pagination

Per `coding-standards.md` § Pagination:

- `GET /api/v1/tasks/{task}/comments` uses **cursor pagination** because tasks can accumulate a large number of comments over time. Ordered by `id` ascending (oldest first) so the conversation reads chronologically as the user pages forward.
- Nested replies under each top-level comment return a **full list** (expected < 50 replies per top-level comment). See `coding-standards.md` — Exception: Small Stable Tables.
- `GET /api/v1/comments/{comment}/documents` uses **cursor pagination**, consistent with all other Document module list endpoints.

### Caching

Per `coding-standards.md` § Caching:

- **Comment lists are not cached** — they are write-heavy and users expect real-time conversation updates.
- **Comment count badges are not cached** in MVP; if needed later, use a tenant-prefixed key with event-driven invalidation.
- The `task_search_index` denormalized comment content is a persistent read model maintained by the Search module, not a Redis cache.
- All Redis keys (if introduced later) must be tenant-prefixed per `coding-standards.md`.

### Rate Limiting

Per `coding-standards.md` § Rate Limiting and `App\Support\RateLimits`:

- `POST /api/v1/tasks/{task}/comments` (create / reply): `RateLimits::MUTATE` (30/min per user)
- `GET /api/v1/tasks/{task}/comments`: `RateLimits::LIST` (60/min per user)
- `POST /api/v1/comments/{comment}/documents`: `RateLimits::MUTATE` (30/min per user)
- `GET /api/v1/comments/{comment}/documents`: `RateLimits::LIST` (60/min per user)

### Database Transactions

Per `coding-standards.md` § Database Transactions:

- **Comment creation** is a single insert; no `DB::transaction()` required.
- **Comment attachment upload** follows Spec 012 rules: single insert, no transaction required.
- **Search index upsert** in `SearchIndexListener` is a single write; no transaction required.
- **Recent activity insert** in `SearchActivityListener` is a single write; no transaction required.

### Error Handling & Logging

Per `coding-standards.md` § Error Handling & Logging:

- Module logging channel: `task` (comments live inside the Task module)
- All service methods use try/catch with `Log::channel('task')`
- Structured context: `tenant_slug`, `action` (e.g., `comment.create`), `entity_type` (`comment`), `entity_id` (comment `public_id`), `performed_by` (author `public_id`)
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages
- Expected domain exceptions: `CommentNotFoundException` (404), `InvalidCommentParentException` (422 — reply to a reply or to a comment on another task)

### Enums

Per `coding-standards.md` § Enum Usage:

- **No new enums are required** for the core comment model.
- Reuse `App\Modules\Document\Enums\DocumentEntityType::Comment` for attachments.
- Reuse `App\Modules\Search\Enums\SearchActivityType::CommentAdded` for recent activity.
- Use enum cases in services and form requests; never raw integers.

### Queue Jobs

Per `coding-standards.md` § Queues & Jobs:

- `CommentCreated` is a domain event that implements `ShouldDispatchAfterCommit`; it is not queued itself.
- Existing Search listeners (`SearchIndexListener`, `SearchActivityListener`) already implement `ShouldQueue` with `$tries = 3` and `$backoff = [30, 60, 120]`; they will be extended to consume `CommentCreated`.
- No new standalone queued jobs are required in MVP.

---

## Out of Scope

- **@mentions and mention notifications** (Feature #146) — V2
- **Internal / department-only comments** (Feature #174) — V2
- **Editing or deleting comments** (Feature #175) — V2
- **Rich text, Markdown, or HTML formatting** — plain text `body` only in MVP
- **Comment reactions / emoji responses** — V2
- **Real-time websockets or live comment streaming** — V2
- **Email or in-app notifications for new comments** — V2 (the event hook exists, but delivery logic is deferred)
- **Comment-level permission grants beyond parent-task visibility** — deferred
- **Comment attachments version restrictions or access controls beyond the parent task** — deferred
- **Including comments in the Spec 006 task timeline endpoint** — comments remain a separate panel in MVP; timeline integration deferred

---

## Open Questions

- [x] Should comment body be bilingual (`body_ar` / `body_en`) or single column? **Resolved:** Single `body` column. The UI accepts text in whichever language the user types. No `body_ar` / `body_en` split in MVP. Comment content is copied to both `comment_content_ar` and `comment_content_en` in the search index for bilingual FTS.
- [x] Should comment list be newest-first, oldest-first, or sortable? **Resolved:** Oldest-first with cursor pagination (`orderBy('id')` ascending). Nested replies return as a full list (expected < 50 per top-level comment), also oldest-first.
- [x] Should comment attachments use inline upload or separate endpoints? **Resolved:** Separate endpoints. Create the comment first; the UI uploads files via `POST /api/v1/comments/{comment}/documents`. Reuses the Document module with no new upload logic in the Task module.
- [x] Should comments appear in the task timeline (Spec 006)? **Resolved:** Keep separate. Comments live in their own panel; they are **not** merged into the Spec 006 timeline response in MVP.
- [x] Should soft-deleted comments be removed from the search index? **Resolved:** Deferred to V2. A future `CommentDeleted` event will trigger search index cleanup. Current `SearchIndexService::upsertForTask()` already filters `whereNull('deleted_at')`.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
