# Spec: External References

> **Number:** 014
> **Date:** 2026-07-01
> **Status:** `completed`
> **Milestone:** M4 — Task Execution & Lifecycle
> **Depends on:** `005-task-execution` (tasks, task visibility, `TaskVisibilityScope`), `002-organization-structure` (departments), `003-iam-abac` (users, ABAC policy engine), `011-search-discovery` (`task_search_index`, `SearchService`, external-reference filter contract)
> **Provides APIs:** External Entity CRUD, Task External Reference CRUD, Search by External Reference integration
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/002-task-board`
> **Author:** OpenCode
> **Branch:** `feat/014-external-references`
> **Base branch:** `main`

---

## Problem

Government and large-enterprise tasks in Gov TMS rarely start from a blank page. A task usually originates from an incoming correspondence number (`وارد-2026-00412`), a ministerial decision, a contract, a vendor invoice, or an authority reference. Without a structured way to link tasks to these external identifiers, users lose the ability to trace work back to its formal source, and follow-up specialists cannot quickly find every task related to a specific external record.

Today the platform can create and progress tasks (Specs 005 and 006), search task titles and stage notes (Spec 011), and filter the follow-up board (Spec 010) — but none of these features can reliably connect a task to an external reference number. The `tasks` table has no reference fields, and the Search module returns 422 for external-reference lookups because the underlying `task_external_references` table does not exist.

Without this spec:

- **Task creators** cannot record the correspondence, contract, or decision number that triggered the task.
- **Follow-up specialists** cannot look up all tasks tied to a single reference number.
- **The follow-up board** cannot filter by external reference, blocking Feature #105.
- **Full-text search** cannot include reference numbers in results, blocking Feature #225.
- **Audit and reporting** cannot show the external context of a task when needed for compliance reviews.

---

## Goal

Deliver the **External Reference** subsystem inside the Task module. The subsystem provides:

1. A tenant-managed catalog of external issuing entities (`external_entities`) so reference sources are normalized and consistent.
2. The ability to attach one or more external references to any task (`task_external_references`).
3. Exact-match search by reference number through the existing Search module endpoint.
4. ABAC-aware read access — a user can only view references on tasks they are authorized to see.

After this spec, authorized users can create and manage external entities, attach references to tasks, list references per task, and find tasks by reference number. The Search module's `external_reference` filter becomes functional, and the follow-up board can later add reference filtering without schema changes.

---

## User Stories

### External Entity Management

- As a **tenant admin**, I want to manage a catalog of external entities (ministries, authorities, vendors, etc.), so that task creators can select a normalized issuing entity instead of typing free text.
- As a **tenant admin**, I want to categorize each external entity by type (government ministry, authority, semi-government, university, hospital, private company, vendor, other), so that the catalog is organized and searchable.
- As a **tenant admin**, I want to activate or deactivate external entities, so that obsolete entities can be retired without breaking historical references.

### Task External References

- As a **task creator**, I want to attach one or more external references to a task, so that the task is linked to the formal correspondence, contract, decision, or record that triggered it.
- As a **task creator**, I want to categorize each reference by type (correspondence, contract, ministerial decision, authority decision, meeting minute, external organization request, vendor reference, other), so that references are semantically meaningful.
- As a **task creator**, I want to select the issuing external entity from a catalog, so that reference sources are consistent and searchable.
- As a **task creator**, I want to add optional notes to a reference, so that I can record context such as a date or sub-number.
- As a **task viewer**, I want to see all external references attached to a task, so that I understand the task's external context.
- As a **task manager**, I want to remove an incorrect reference from a task, so that the task remains accurately linked.

### Search & Follow-Up

- As a **follow-up specialist**, I want to enter a reference number in the search bar and see all tasks linked to it, so that I can track all work related to a specific external record.
- As a **follow-up specialist**, I want the follow-up board to let me filter by external reference number, so that I can narrow the board to tasks tied to a specific correspondence or contract.

---

## Acceptance Criteria

### `external_entities` Table

- [x] `external_entities` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `name_en`, `name_ar` (required), `entity_type` (TINYINT), `is_active` (boolean, default true), `created_at`, `updated_at`, `deleted_at`
- [x] Model extends `TenantModel`, uses `public_id` for route model binding; no `tenant_id` column
- [x] Model relationships: references used in `taskExternalReferences`
- [x] Soft deletes only — no hard delete endpoint in MVP

### `task_external_references` Table

- [x] `task_external_references` table in tenant DB includes: `id`, `public_id` (UUID v7, unique), `task_id` (FK to `tasks.id`), `reference_type` (TINYINT), `reference_number` (VARCHAR 100, required), `external_entity_id` (nullable FK to `external_entities.id`), `notes` (text, nullable), `created_at`
- [x] Model extends `TenantModel`, uses `public_id`; no `tenant_id` column
- [x] Model relationships: `task()`, `externalEntity()`
- [x] Composite index on `(task_id)` and `(reference_number)` for task-scoped listing and exact-match search

### External Entity CRUD

- [x] `GET /api/v1/tasks/external-entities` — list active external entities ordered by `name_ar`. Full list (expected < 200). Requires authentication.
- [x] `POST /api/v1/tasks/external-entities` — create an external entity. Requires `task.manage_external_entities` capability. Request body: `name_ar`, `name_en` (optional, copies Arabic if empty), `entity_type`.
- [x] `GET /api/v1/tasks/external-entities/{entity}` — show an external entity. Requires authentication.
- [x] `PUT /api/v1/tasks/external-entities/{entity}` — update an external entity. Requires `task.manage_external_entities`.
- [x] `POST /api/v1/tasks/external-entities/{entity}/deactivate` — set `is_active = false`. Requires `task.manage_external_entities`.
- [x] `POST /api/v1/tasks/external-entities/{entity}/reactivate` — set `is_active = true`. Requires `task.manage_external_entities`.
- [x] `name_ar` required; `name_en` optional (system copies `name_ar` if empty)
- [x] Deactivated entities remain visible in historical references; new references can only use active entities

### Task External Reference CRUD

- [x] `GET /api/v1/tasks/{task}/external-references` — list all external references attached to a task, ordered by `created_at`. Returns reference `public_id`, `reference_type`, `reference_number`, issuing entity (`public_id`, `name_ar`, `name_en`, `entity_type`), and `notes`. ABAC visibility enforced via `TaskVisibilityScope`. Requires authentication.
- [x] `POST /api/v1/tasks/{task}/external-references` — attach a new reference to a task. Requires task visibility + (`task.manage` capability OR task initiator). Request body: `reference_type`, `reference_number`, `external_entity_id` (optional), `notes` (optional).
- [x] `PUT /api/v1/tasks/{task}/external-references/{reference}` — update a reference's type, number, issuing entity, or notes. Requires task visibility + (`task.manage` capability OR task initiator). Allowed while task is in any non-deleted status.
- [x] `DELETE /api/v1/tasks/{task}/external-references/{reference}` — soft-delete a reference from a task. Requires task visibility + (`task.manage` capability OR task initiator). Allowed while task is in any non-deleted status.
- [x] All responses expose `public_id` only — never internal `id`

### Authorization

- [x] Listing/adding/updating/deleting task references requires the caller to be able to view the parent task per `TaskVisibilityScope`
- [x] Mutating task references additionally requires the task initiator or `task.manage` capability (mirrors task update/delete rules from Spec 005)
- [x] Confidential tasks: references are visible only to users who can view the confidential task
- [x] External entity CRUD requires `task.manage_external_entities` capability

### Search Integration

- [x] `GET /api/v1/search/tasks?external_reference={number}` performs exact-match lookup on `task_external_references.reference_number` and returns ABAC-filtered tasks
- [x] The existing `ExternalReferenceSearchNotAvailableException` (422) is removed / no longer thrown once this spec is implemented
- [x] Reference numbers are **not** included in the full-text `q` search; they are queried only through the dedicated `external_reference` filter
- [x] Search results include the matched reference metadata in the response (at least `reference_type` and `reference_number`)

### Events & Audit

- [x] Domain events emitted: `ExternalReferenceCreated`, `ExternalReferenceUpdated`, `ExternalReferenceDeleted` (task-level reference lifecycle); `ExternalEntityCreated`, `ExternalEntityUpdated`, `ExternalEntityDeactivated`, `ExternalEntityReactivated` (entity catalog lifecycle)
- [x] All domain events implement `ShouldDispatchAfterCommit`
- [x] All events implement `ProvidesAuditData` so the Audit module records them automatically
- [x] No direct writes to `audit_events` from the Task module

### Tests

- [x] Feature tests cover: create external entity, update entity, deactivate/reactivate entity, list entities, create task reference, list task references, update task reference, delete task reference, ABAC deny when user cannot view task, confidential task reference visibility, search by exact reference number, search with no match returns empty, invalid reference type rejected, inactive entity rejected for new references

---

## Non-Functional Requirements

### Pagination

Per `coding-standards.md` § Pagination:

- `GET /api/v1/tasks/{task}/external-references` uses **cursor pagination** because long-running tasks can accumulate many references over time. Ordered by `id` ascending.
- `GET /api/v1/tasks/external-entities` returns **full list** of active entities (expected < 200 rows per tenant). See `coding-standards.md` — Exception: Small Stable Tables.
- Search results via `GET /api/v1/search/tasks` continue to use the **cursor pagination** already defined in Spec 011.

### Caching

Per `coding-standards.md` § Caching:

- External entity catalog is cached at `{tenant_slug}:task:external_entities:active` with TTL 300s (warm tier). Invalidated on any entity create/update/deactivate/reactivate event.
- Task reference lists are **not cached** — they are write-heavy and users expect attached references to appear immediately.
- All Redis cache keys are tenant-prefixed per `coding-standards.md`.

### Rate Limiting

Per `coding-standards.md` § Rate Limiting and `App\Support\RateLimits`:

- External entity mutating endpoints (create, update, deactivate, reactivate): `RateLimits::MUTATE` (30/min per user)
- External entity list/show endpoints: `RateLimits::LIST` (60/min per user)
- Task reference mutating endpoints (create, update, delete): `RateLimits::MUTATE` (30/min per user)
- Task reference list endpoint: `RateLimits::LIST` (60/min per user)
- Search endpoint rate limiting remains as defined in Spec 011 (`RateLimits::LIST`)

### Database Transactions

Per `coding-standards.md` § Database Transactions:

- **External entity create/update/deactivate/reactivate**: single write operations; no `DB::transaction()` required.
- **Task reference create/update/delete**: single write operations; no `DB::transaction()` required.
- **Search index updates** triggered by reference events are handled asynchronously by existing Search listeners; no transaction required in this spec.

### Error Handling & Logging

Per `coding-standards.md` § Error Handling & Logging:

- Module logging channel: `task` (external references live inside the Task module)
- All service methods use try/catch with `Log::channel('task')`
- Structured context: `tenant_slug`, `action` (e.g., `external_reference.create`, `external_entity.create`), `entity_type` (`external_reference` / `external_entity`), `entity_id` (public_id), `performed_by` (actor public_id)
- Domain exceptions extend the project `DomainException` base class and render safe JSON messages
- Expected domain exceptions:
  - `ExternalEntityNotFoundException` (404)
  - `ExternalEntityInactiveException` (422 — attempt to use an inactive entity for a new reference)
  - `ExternalReferenceNotFoundException` (404)
  - `TaskNotVisibleException` (403 — reused from existing Task module)

### Enums

Per `coding-standards.md` § Enum Usage:

- Create `ExternalReferenceType` enum in `app/Modules/Task/Enums/ExternalReferenceType.php`:
  - `Correspondence = 1`, `Contract = 2`, `MinisterialDecision = 3`, `AuthorityDecision = 4`, `MeetingMinute = 5`, `ExternalOrgRequest = 6`, `VendorReference = 7`, `Other = 8`
- Create `ExternalEntityType` enum in `app/Modules/Task/Enums/ExternalEntityType.php`:
  - `GovernmentMinistry = 1`, `GovernmentAuthority = 2`, `SemiGovernment = 3`, `University = 4`, `Hospital = 5`, `PrivateCompany = 6`, `Vendor = 7`, `Other = 8`
- Use enum classes in all form requests via `Rule::enum(ClassName::class)`
- Use enum cases in all service and controller logic — never raw integers

### Queue Jobs

Per `coding-standards.md` § Queues & Jobs:

- `ExternalReferenceCreated`, `ExternalReferenceUpdated`, `ExternalReferenceDeleted` are domain events that implement `ShouldDispatchAfterCommit`; they are not queued themselves.
- Existing Search listeners (`SearchIndexListener`) may be extended to re-index task reference metadata if the Search module maintains a denormalized reference column. No new standalone queued jobs are required in MVP.
- No queue jobs required for external entity catalog operations — they are synchronous CRUD.

---

## Out of Scope

- **External entity-to-entity relationships or hierarchies** — V2.
- **Automatic external reference extraction** from uploaded documents — V2/V3.
- **Validation of reference number formats** by type (e.g., regex for correspondence numbers) — V2.
- **Cross-tenant or cross-organization external entity sharing** — each tenant manages its own catalog.
- **External reference summary views** showing all tasks grouped under a single reference number (Feature #226, #227) — V2.
- **DMS / correspondence document management** — explicitly out of scope for the entire platform per `architecture.md` and `03_Module_Boundary_Map.md`.
- **G2G messaging or digital identity integrations** — V3.
- **Help Center article search by reference** — deferred to `020-help-center`.
- **Reference-level attachments or document uploads** — documents attach to tasks/comments/stage outputs via Spec 012, not to references.

---

## Open Questions

- [x] **Capability name for external entity management:** Should it be `task.manage_external_entities` or a more general `task.manage_references`? **Resolution:** `task.manage_external_entities` added to `CapabilitySeeder` as a system-defined capability.
- [x] **Who can attach references to a task?** Task initiator only, or any user who can view the task? **Resolution:** Mirror Spec 005 draft-task edit rules — task initiator or user with `task.manage` capability. This keeps mutation authority narrow while allowing admins to correct errors.
- [x] **Can references be added to a completed or cancelled task?** **Resolution:** Yes, references are metadata and can be added/removed on any non-deleted task; the task status does not restrict reference management. This supports late discovery of related correspondence.
- [x] **Should deactivated external entities be rejected for new references?** **Resolution:** Yes — new references must point to active entities. Historical references to deactivated entities remain valid and visible.
- [x] **Should reference numbers be unique per tenant?** **Resolution:** No — the same external document (e.g., a contract) can legitimately be linked to multiple tasks. Uniqueness is not enforced.
- [x] **Should the search endpoint include reference numbers in the full-text `q` query?** **Resolution:** No — reference numbers are searched only via the dedicated `external_reference` exact-match filter. This avoids false positives in fuzzy FTS and matches Feature #225.
- [x] **Should `external_entities` live in the Task module or a separate ExternalReference module?** **Resolution:** Keep inside the Task module per `03_Module_Boundary_Map.md` Feature-to-Module mapping ("Task Management — External Reference Linking" → Task). This avoids an extra bounded context for two small tables.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
