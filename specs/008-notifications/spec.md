# Spec: Notifications

> **Number:** 008
> **Date:** 2026-06-13
> **Status:** `completed`
> **Milestone:** M5 — SLA, Escalation & Notifications
> **Depends on:** `003-iam-abac` (users, `preferred_language`, ABAC policy engine), `005-task-execution` (tasks, task lifecycle events: launched, suspended, resumed, cancelled, completed; assignment created events), `006-stage-lifecycle` (stage/sub-stage advanced, returned, completed, assignment override events), `007-sla-escalation` (`SlaWarningTriggered`, `SlaBreached`, `EscalationCreated`, `EscalationResolved` events)
> **Provides APIs:** notification list (cursor-paginated), unread count, mark-as-read (single), mark-all-as-read
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/012-personal-workspace` (notifications center — confirm pairing)
> **Author:** Momentum init
> **Branch:** `feat/008-notifications`
> **Base branch:** `main`

---

## Problem

Specs 005, 006, and 007 made tasks move through stages and made the SLA engine emit warning, breach, and escalation events. But none of that activity reaches the people who must act on it. A new stage assignee is not told they now own accountability. A manager receiving an escalation has no alert. An initiator never learns their task completed, was cancelled, suspended, or resumed.

Today the platform emits rich domain events (`StageAssignmentCreated`, `StageInstanceReturned`, `TaskCompleted`, `TaskCancelled`, `TaskSuspended`, `TaskResumed`, `SlaWarningTriggered`, `SlaBreached`, `EscalationCreated`) but there is no module that:

- listens to those events and decides who should be notified
- persists a per-user notification record with read/unread state
- delivers an in-app notification the frontend notifications center can render
- delivers an email to the recipient's registered address
- renders the message in the recipient's `preferred_language` (Arabic or English)
- exposes APIs to list notifications, show the unread count, and mark notifications read

Without this spec, the follow-up promise of Gov TMS is incomplete: accountability changes silently, SLA warnings go unseen, and escalations sit unread. The Notification module is the operational bridge between domain events and the humans responsible for the work.

---

## Goal

Deliver the Notification module: an event-driven, per-tenant notification system that consumes Task and Tracking domain events, resolves recipients, persists notifications using the Laravel notification convention, and delivers them through in-app and email channels in the recipient's preferred language. The module exposes read APIs for the in-app notifications center (list, unread count, mark read).

The module only consumes events emitted by other modules and writes to its own `notifications` table. It never writes to Task, Tracking, IAM, or Organization tables. SMS, WhatsApp, @mention notifications, delegation-activity notifications, and per-user channel preferences are explicitly out of scope (V2).

---

## User Stories

### Receiving Notifications

- As a **stage assignee**, I want an immediate notification when a stage or sub-stage is assigned to me, so that I know I now own accountability.
- As a **stage assignee**, I want a notification when a task is returned to a stage I own, so that I know accountability has reverted to me.
- As a **stage assignee**, I want a notification when an SLA warning fires on my active stage, so that I can act before it breaches.
- As a **stage assignee and manager**, I want a notification when an SLA breach occurs, so that the bottleneck is surfaced immediately.
- As a **manager**, I want a notification when a task is escalated to me with full task and stage context, so that I can decide what action to take.
- As a **task initiator**, I want a notification when my task is completed, cancelled, suspended, or resumed, so that I stay informed about work I opened without owning a stage.
- As a **stage assignee who completed a stage**, I want a confirmation notification when the task advances from my stage, so that I know my action moved the work forward.
- As a **recipient**, I want notifications and emails in my preferred language, so that I can read them clearly.

### Managing Notifications

- As any **user**, I want to see my notifications in a list, newest first, so that I can review what happened.
- As any **user**, I want to see how many unread notifications I have, so that I know whether I need to check.
- As any **user**, I want to mark a notification as read, so that my unread count is accurate.
- As any **user**, I want to mark all notifications as read at once, so that I can clear my unread state quickly.

### System

- As the **system**, I want to deliver notifications asynchronously via queued jobs, so that domain transactions and HTTP requests are never blocked by SMTP calls.
- As the **system**, I want notification creation to be idempotent per event, so that retried jobs or replayed events do not create duplicate notifications.

---

## Acceptance Criteria

### Notifications Table

- [x] `notifications` table in tenant DB follows the Laravel notification convention and includes: `id` (UUID), `type` (notification class name), `notifiable_type`, `notifiable_id`, `data` (text/JSON), `read_at` (nullable), `created_at`, `updated_at`
- [x] ~~A `public_id` (UUID v7, unique) column is added~~ **Superseded: UUID `id` PK serves as the API addressable identifier; no separate `public_id` needed.**
- [x] `notifiable_type` / `notifiable_id` are polymorphic (references `users` as notifiable, not task/stage/escalation directly)
- [x] `data` payload stores at minimum: `title_ar`, `title_en`, `body_ar`, `body_en`, `action_url`, `task_public_id`, and relevant stage/sub-stage/escalation public IDs
- [x] Composite index exists on `(notifiable_type, notifiable_id, read_at)`
- [x] The Notification module never writes to `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `escalations`, `sla_timer_instances`, `users`, or any Organization/IAM table

### Event Consumption & Recipient Resolution

- [x] Listener creates an **assignment received** notification for each new assignee on `StageAssignmentCreated` (stage and sub-stage)
- [x] Listener creates a **task returned** notification for the assignees of the return-target stage on `StageInstanceReturned`
- [x] Listener creates a **task advanced** confirmation notification for the assignees who completed the prior stage on `StageInstanceAdvanced`
- [x] Listener creates an **SLA warning** notification for active assignees on `SlaWarningTriggered`
- [x] Listener creates an **SLA breach** notification for active assignees (manager notified separately via `EscalationCreated`) on `SlaBreached`
- [x] Listener creates an **escalation received** notification for the escalation target user on `EscalationCreated`
- [x] Listener creates a **task completed** notification for the task initiator on `TaskCompleted`
- [x] Listener creates a **task cancelled** notification for all active assignees and the initiator on `TaskCancelled`
- [x] Listener creates a **task suspended** notification (including the suspension reason) for all active assignees and the initiator on `TaskSuspended`
- [x] Listener creates a **task resumed** notification for all active assignees and the initiator on `TaskResumed`
- [x] Recipient resolution uses read-only `NotificationRecipientResolver` service calls; listeners never write to other modules
- [x] A user is not notified for actions they themselves triggered where redundant (inactive users are skipped)
- [x] All listeners are idempotent: replaying the same event does not create duplicate notifications (guarded by `data.dedupe_key`)

### Channels & Localization

- [x] Each notification is delivered to two channels: in-app (persisted `notifications` row) and email (queued mail, `ShouldQueue`)
- [x] Email and in-app content render in the recipient's `users.preferred_language` (1 = Arabic, 2 = English)
- [x] Arabic content is always present; English falls back to Arabic when an English string is unavailable
- [x] Email delivery failures do not prevent the in-app notification from persisting (queued jobs retry 3x; in-app row written inline)
- [x] Notification payloads contain only task/stage metadata (IDs, names); no full PII or confidential body content beyond what the recipient is authorized to see

### APIs

- [x] `GET /api/v1/notifications` — cursor-paginated list of the authenticated user's notifications, newest first; supports `read` filter (`unread`, `read`, `all`, default `all`). Returns only the caller's own notifications.
- [x] `GET /api/v1/notifications/unread-count` — returns the authenticated user's unread notification count
- [x] `POST /api/v1/notifications/{notification}/read` — marks a single notification (by UUID `id`) as read; 404 if it does not belong to the caller
- [x] `POST /api/v1/notifications/read-all` — marks all of the caller's unread notifications as read
- [x] All endpoints require authentication and the `X-Tenant` header; a user can only ever read or mutate their own notifications
- [x] All responses use API Resources and expose UUID `id` (not internal `id`)
- [x] List endpoint uses cursor pagination and returns `{data, next_cursor, has_more}`

### Domain Events

- [x] No new Notification-specific domain events are emitted in MVP (avoids event loops; Audit consumption deferred to Spec 015)
- [x] Notification creation does not emit events that the Notification module itself consumes (no event loops)
- [x] Audit (Spec 015) may later consume Notification events; structured log context includes `tenant_slug`, recipient user public ID, notification ID, and source event type

### General

- [x] All Notification data lives in the tenant DB; no `tenant_id` columns are added
- [x] Redis/cache keys (unread count) are tenant-prefixed
- [x] All listeners, jobs, and service methods emit structured logs through the `notification` logging channel
- [x] Feature tests cover: assignment notification, return notification, advance confirmation, SLA warning, SLA breach (assignees), escalation received, task completed/cancelled/suspended/resumed, language selection, list with read filter, unread count, mark single read, mark all read, ABAC isolation (user cannot read another user's notifications), cursor pagination, and idempotent re-delivery

---

## Non-Functional Requirements

### Pagination

- `GET /api/v1/notifications` uses **cursor pagination** because per-user notification history can exceed 1000 rows. See `coding-standards.md` — Pagination Strategy.
- Cursor pagination requires `orderBy('id')` (newest-first via descending id) and returns `{data, next_cursor, has_more}`.
- `GET /api/v1/notifications/unread-count` returns a single scalar object and does not paginate.

### Caching

- Notification list results are **not cached**; they are per-user, time-sensitive, and change on every read action.
- The unread count **may** be cached at `{tenant_slug}:notification:unread_count:{user_public_id}` with TTL 60s (hot tier), invalidated on notification created / marked-read / mark-all-read events. Caching is optional; correctness must not depend on it. See `coding-standards.md` — Caching.
- All cache keys must be tenant-prefixed and invalidated by domain events, not TTL alone.

### Rate Limiting

- List, unread-count, and any read endpoints: `RateLimits::LIST` (60/min per user).
- Mark-as-read and mark-all-as-read endpoints: `RateLimits::MUTATE` (30/min per user).
- No route-level throttle strings; controllers use the `HasRateLimiting` trait and `RateLimits` constants per `coding-standards.md` — Rate Limiting.

### Database Transactions

- Creating a notification (single insert) is a single write and does not require a transaction.
- Fan-out creation of multiple notifications for one event (e.g. SLA breach to several assignees + manager) uses `DB::transaction()` so all-or-nothing per event, plus the post-commit `NotificationCreated` events.
- `mark-all-as-read` (bulk update) uses `DB::transaction()`.
- Single mark-as-read is a single update and does not require a transaction.
- All transaction boundaries follow `coding-standards.md` — Database Transactions.

### Error Handling & Logging

- Module logging channel: `notification` (already defined in `config/logging.php` per coding-standards).
- All listeners, queued jobs, and service methods use try/catch with `Log::channel('notification')`.
- Structured log context includes: `tenant_slug`, `action` (e.g. `notification.create`, `notification.read`), `entity_type`, `entity_id` (notification public ID), `performed_by` (`system` for event-driven creation), plus `source_event` and recipient user public ID.
- Domain exceptions extend the project `DomainException` base class and render safe JSON.
- Expected domain exceptions: `NotificationNotFoundException` (or rely on route-model-binding 404), `NotificationAccessDeniedException` (caller is not the owner).
- Email/PII rule: never log full notification bodies containing PII; log identifiers and the source event only. See `security-policy.md` — PII.

### Enums

- Create `NotificationType` enum in `app/Modules/Notification/Enums/NotificationType.php` enumerating MVP notification kinds: `StageAssignmentReceived`, `TaskReturned`, `TaskAdvanced`, `SlaWarning`, `SlaBreach`, `EscalationReceived`, `TaskCompleted`, `TaskCancelled`, `TaskSuspended`, `TaskResumed`.
- Create `NotificationChannel` enum in `app/Modules/Notification/Enums/NotificationChannel.php`: `InApp = 1`, `Email = 2`.
- Reuse the existing language representation on `users.preferred_language` (1 = Arabic, 2 = English); do not duplicate a language enum if one already exists in `app/Enums/`.
- Form Requests use `Rule::enum(...)`; services and listeners use enum cases, never raw integers. See `coding-standards.md` — Enum Usage.

### Queue Jobs

- Email delivery runs through queued Laravel notifications / mailables implementing `ShouldQueue`, with `public int $tries = 3` and `public array $backoff = [30, 60, 120]`.
- In-app persistence may run inline in the listener or via a queued job; if queued, jobs carry tenant context (`tenant_slug`) so the worker switches to the correct tenant DB before writing.
- Domain events implement `ShouldDispatchAfterCommit`.
- Failed jobs implement `failed()` and log to the `notification` channel with recipient and source-event context.
- Queue behavior follows `coding-standards.md` — Queues & Jobs, including tenant context in payloads.

---

## Out of Scope

- SMS notifications (feature #148) — V2.
- WhatsApp notifications (feature #149) — V2.
- @mention / comment notifications (feature #146) — depends on Spec 013 (comments); V2.
- Delegation-activity notifications (feature #147) — V2.
- Per-user notification channel preferences (feature #150) and do-not-disturb schedule (feature #151) — V2.
- Notification template management UI / admin-editable message text (feature #241) — V2.
- Digest / scheduled summary notifications (feature #166) — V2.
- The Analytics personal-workspace "My notifications center" aggregation view beyond these APIs — Analytics spec consumes these APIs.
- Audit event persistence — Spec 015 consumes this spec's events.
- Real-time websocket/broadcast push — MVP delivers in-app via persisted rows polled by the frontend; broadcast is deferred.
- Modifying Task, Tracking, IAM, or Organization tables from Notification services.

---

## Open Questions (Answered)

- [x] Frontend pairing: should the notifications center be paired with a dedicated frontend spec or the personal-workspace spec? **Decision: pair with `../frontend/specs/012-personal-workspace` (notifications center).** The personal workspace already owns "My notifications center" (feature #215).
- [x] Recipient carrying vs resolution: should consumed events carry resolved recipient IDs, or should listeners resolve them? **Decision: resolve recipients inside listeners via read-only `NotificationRecipientResolver` service calls.** Existing Task/Tracking events carry only the model, not recipient ID lists. Avoids modifying locked 005/006/007 contracts.
- [x] Manager resolution on SLA breach: should the Notification module independently resolve managers, or piggyback on Spec 007's escalation target? **Decision: notify the escalation target from the `EscalationCreated` event; do NOT re-resolve managers.** Spec 007 already resolves and persists `escalated_to_user_id`. Re-resolving would duplicate logic and risk drift.
- [x] Email transport in MVP: confirm SMTP provisioning and whether tenant-level from-address branding is needed. **Decision: use platform default SMTP `from`; tenant branding deferred.** SMTP is the only mail service for MVP; tenant-level branding is V2 (feature #234).
- [x] Bulk read-by-array vs read-all: should consumers pass an array of `public_id`s for bulk-read, or just a single read-all endpoint? **Decision: ship single mark-read + mark-all-read only.** Smallest safe change; matches spec acceptance criteria.
- [x] Retention: do read notifications need an automatic prune/retention window? **Decision: keep indefinitely for MVP.** No archive/retention spec exists yet; revisit with Spec 015.
- [x] Notifications table shape: should a `public_id` column be added to the `notifications` table as per initial spec requirement? **Decision: use Laravel default `notifications` schema with UUID `id` PK. No `public_id` column.** UUID `id` is already non-enumerable, satisfying the security intent of `public_id`.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
