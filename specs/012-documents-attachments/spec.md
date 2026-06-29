# Spec: Documents & Attachments

> **Number:** 012
> **Date:** 2026-06-29
> **Status:** `completed`
> **Milestone:** M7 — Documents, Audit, Onboarding & Help
> **Depends on:** `005-task-execution` (task existence, visibility rules, initiator/assignee relationships); `006-stage-lifecycle` (stage/sub-stage output context)
> **Provides APIs:** Task/Stage/Sub-stage attachment upload and list (6 endpoints), Document metadata show, download, preview (3), Document versioning (create + list versions, 2), Document soft-delete (1), Comment attachment endpoints deferred to Spec 013 (2)
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/003-task-details`
> **Author:** OpenCode
> **Branch:** `feat/012-documents-attachments`
> **Base branch:** `main`

---

## Problem

Government and large-organization tasks are rarely self-contained: a single task may carry ministerial directives, correspondence scans, legal opinions, budget spreadsheets, signed approvals, and supporting evidence. Without a centralized attachment layer, users resort to email, shared drives, or external messengers, fragmenting the audit trail and making it impossible to know which file version is authoritative.

The Task module already creates tasks, stages, and comments, but it has no way to persist file metadata or stream files from object storage. This spec adds a Document module that stores attachment metadata in the tenant database and files in tenant-prefixed object storage, inherits task visibility from the existing ABAC model, and preserves version history so later reviewers always know which file was current at any point in the lifecycle.

---

## Goal

Deliver a reusable Document module that allows authorized users to attach files to tasks, stage/sub-stage outputs, and comments; preview or download those files; and replace a file with a new version while preserving all older versions. The module must enforce task visibility rules from Spec 003/005, emit audit events consumed by the Audit module, and remain storage-provider agnostic (S3/MinIO/local). After this spec, downstream modules (Comments 013, Help Center 020, Audit 015) can rely on the Document module for file persistence rather than inventing their own attachment logic.

---

## User Stories

### Task Participants

- As a **task initiator**, I want to upload supporting documents when I create or edit a task, so that all stage assignees have the context they need in one place.
- As a **stage assignee**, I want to attach output documents (e.g., a drafted response or approval scan) when I complete my stage, so that the next stage owner can review my work.
- As a **commenter**, I want to attach a file to my comment, so that I can share evidence or clarifications without leaving the task thread.
- As a **task viewer**, I want to preview a PDF or image inline and download any attachment, so that I can review evidence without switching systems.
- As a **task owner or admin**, I want to replace an attachment with a newer version while keeping the old version visible, so that the task history remains accurate and auditable.

### Governance & Audit

- As a **compliance officer**, I want every upload, download, and version replacement logged immutably, so that I can reconstruct who accessed which file and when.
- As a **tenant admin**, I want attachment visibility to follow the parent task’s classification rules, so that confidential task files are not exposed to unauthorized users.

---

## Acceptance Criteria

### `documents` Table

- [x] `documents` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `uploader_user_id`, `original_filename`, `storage_path`, `mime_type`, `size_bytes`, `entity_type` (TINYINT), `entity_id`, `version_number`, `root_document_id`, `parent_document_id`, `description`, `created_at`, `deleted_at`
- [x] Model extends `TenantModel`, uses `public_id` for route model binding; no `tenant_id` column
- [x] Composite index on `(entity_type, entity_id)` for fast polymorphic lookups
- [x] Self-referential `parent_document_id` links each version to its predecessor; `root_document_id` identifies the chain root

### Capabilities

- [x] New system capability `task.manage_documents` granted to positions that may upload/delete attachments on tasks they can view
- [x] New system capability `task.view_documents` granted to all internal users by default; attachment listing respects parent task visibility and classification rules
- [x] Capabilities seeded via `CapabilitySeeder`

### Upload & Attach

- [x] `POST /api/v1/tasks/{task}/documents` accepts `file` and optional `description`; stores file in tenant-prefixed object storage; creates `documents` row with `entity_type = DocumentEntityType::Task`
- [x] `POST /api/v1/task-stage-instances/{stage}/documents` and `POST /api/v1/task-sub-stage-instances/{subStage}/documents` attach to stage/sub-stage output (`entity_type = DocumentEntityType::StageOutput`)
- [ ] `POST /api/v1/comments/{comment}/documents` attaches to a comment (`entity_type = DocumentEntityType::Comment`) — deferred until Spec 013 creates Comment model
- [x] Upload rejected with 422 if file exceeds configured max size, has disallowed MIME type, or fails storage provider error
- [x] Only users who can view the parent task (per `TaskVisibilityScope`) and have `task.manage_documents` may upload; otherwise 403

### List, Preview, Download

- [x] `GET /api/v1/tasks/{task}/documents` returns cursor-paginated list of current-version attachments for the task
- [x] `GET /api/v1/task-stage-instances/{stage}/documents`, `GET /api/v1/task-sub-stage-instances/{subStage}/documents` behave analogously
- [ ] `GET /api/v1/comments/{comment}/documents` — deferred until Spec 013
- [x] `GET /api/v1/documents/{document}` returns metadata (public_id, original_filename, mime_type, size_bytes, version_number, uploader, created_at, download/preview URLs)
- [x] `GET /api/v1/documents/{document}/download` streams the file with correct `Content-Disposition: attachment`; 404 if file missing from storage
- [x] `GET /api/v1/documents/{document}/preview` streams the file inline with `Content-Disposition: inline` for supported MIME categories (PDF, images); returns 422 for unsupported types
- [x] All read endpoints enforce parent task visibility via `guardTaskVisibility`; confidential task attachments require the same access as the task itself

### Versioning

- [x] `POST /api/v1/documents/{document}/versions` accepts a new `file`, creates a successor `documents` row with `version_number = parent.version_number + 1` and `parent_document_id = parent.id`, and stores the new file
- [x] `GET /api/v1/documents/{document}/versions` returns all versions of the document in chronological order (cursor-paginated)
- [x] Listing endpoints return only the latest version of each document chain by default (via `whereDoesntHave('nextVersion')` scope)
- [x] Soft-deleting a document deletes the whole chain (soft delete cascades to all versions in the transaction)

### Deletion

- [x] `DELETE /api/v1/documents/{document}` soft-deletes the document chain; does not delete the underlying object storage file immediately (retained for audit / restore)
- [x] Only the uploader or a user with `task.manage_documents` within scope may delete; otherwise 403

### Events & Audit

- [x] `DocumentUploaded`, `DocumentVersionCreated`, `DocumentDownloaded`, `DocumentPreviewed`, `DocumentDeleted` domain events implement `ShouldDispatchAfterCommit`
- [x] Events include document `public_id`; `DocumentDeleted` also includes `chainRootId` for full chain awareness

---

## Non-Functional Requirements

### Pagination

Per `coding-standards.md` § Pagination:

- `GET /api/v1/.../documents` and `GET /api/v1/documents/{document}/versions` use **cursor pagination** (`cursorPaginate()` ordered by `id`) because attachments can exceed 1,000 rows per tenant over time.
- Small reference data (none in this spec) would return full lists; all document endpoints are cursor-paginated.

### Caching

Per `coding-standards.md` § Caching:

- Do **not** cache cursor-paginated attachment lists; content changes frequently and stale pages are confusing.
- Tenant-prefixed cache key `{tenant_slug}:document:mime_categories:allowed` caches the allowed MIME category list (cold TTL 3,600s), invalidated only when tenant settings change.
- Signed/presigned download/preview URLs (if used for S3) are generated per request and never cached.

### Rate Limiting

Per `coding-standards.md` § Rate Limiting and `App\Support\RateLimits`:

- Upload endpoints (`POST /api/v1/.../documents`, `POST /api/v1/documents/{document}/versions`): `RateLimits::MUTATE` (30/min per user)
- List/metadata endpoints: `RateLimits::LIST` (60/min per user)
- Download/preview endpoints: `RateLimits::LIST` (60/min per user) — file streaming is read-heavy but still bounded per user

### Database Transactions

Per `coding-standards.md` § Database Transactions:

- Document upload + version row creation: single insert, no transaction required; if storage succeeds but DB fails, the orphan object is acceptable for MVP (noted in open questions).
- Version replacement (new file + new row + optional old-file metadata update): wrap in `DB::transaction()`.
- Soft-delete of a document chain (multiple rows): wrap in `DB::transaction()`.

### Error Handling & Logging

Per `coding-standards.md` § Error Handling & Logging:

- Module logging channel: `document` (add to `config/logging.php`)
- All service methods use try/catch with `Log::channel('document')` and structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `document_id`, `performed_by`
- Domain exceptions registered in `bootstrap/app.php`: `DocumentNotFoundException` (404), `UnsupportedPreviewTypeException` (422), `StorageProviderException` (500)

### Enums

Per `coding-standards.md` § Enum Usage:

- Create `App\Modules\Document\Enums\DocumentEntityType` (int-backed): `Task = 1`, `Comment = 2`, `StageOutput = 3`, `HelpArticle = 4` (reserved for Spec 020)
- Create `App\Modules\Document\Enums\DocumentMimeCategory` (int-backed): `Pdf = 1`, `Image = 2`, `Word = 3`, `Excel = 4`, `Other = 5`
- Use `Rule::enum()` in form requests; never raw integers in business logic

### Queue Jobs

Per `coding-standards.md` § Queues & Jobs:

- Domain events implement `ShouldDispatchAfterCommit`; listeners may queue if they perform heavy work (none required in MVP)
- No standalone queued jobs in MVP; file upload, preview, and download are synchronous
- Virus scanning, thumbnail generation, and OCR are deferred to V2/V3

---

## Out of Scope

- Document-level access restrictions (feature #182) — V2
- Linking one document to multiple tasks (feature #181) — V2
- Help Center article images (uses the same `documents` table but specified in Spec 020)
- Virus scanning, malware detection, content indexing, OCR, or full-text search inside files
- Digital signatures or certified document workflows
- Watermarking, DRM, or offline sync
- Bulk import from external DMS/ERP systems
- Real-time collaborative editing

---

## Open Questions

- [x] What is the default storage disk for MVP: `local`, `s3`, or `MinIO`? **Decision:** Use Laravel `Storage` facade with the configured default disk (`FILESYSTEM_DISK` env, default `local` for MVP). Code must never call S3 SDK directly; S3/MinIO work by changing `config/filesystems.php` disk config. All paths are tenant-prefixed.
- [x] What is the maximum upload file size per tenant? **Decision:** 20 MB default, overridable per tenant in `tenants.settings.max_upload_size_mb`.
- [x] Which MIME types are explicitly allowed? **Decision:** PDF, JPG/JPEG, PNG, GIF, DOC/DOCX, XLS/XLSX; reject executables and archives. Configurable per tenant in `tenants.settings.allowed_mime_types`.
- [x] Should object storage files be physically deleted on soft-delete, or retained indefinitely? **Decision:** Retain for MVP; add a future garbage-collection job.
- [x] Should downloads/preview use presigned URLs for S3, or stream through the API? **Decision:** Stream through API for consistent ABAC enforcement; presigned URLs deferred to V2.
- [x] Do we need a `description` or `display_name` column on `documents` beyond `original_filename`? **Decision:** Add nullable `description` text column.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
