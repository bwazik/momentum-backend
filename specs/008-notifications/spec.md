# Spec: Notifications

> **Number:** 008
> **Date:** 2026-06-13
> **Status:** `draft`
> **Milestone:** M5 — SLA, Escalation & Notifications
> **Depends on:** `003-iam-abac` (users, `preferred_language`, ABAC policy engine), `005-task-execution` (tasks, task lifecycle events: launched, suspended, resumed, cancelled, completed; assignment created events), `006-stage-lifecycle` (stage/sub-stage advanced, returned, completed, assignment override events), `007-sla-escalation` (`SlaWarningTriggered`, `SlaBreached`, `EscalationCreated`, `EscalationResolved` events)
> **Provides APIs:** notification list (cursor-paginated), unread count, mark-as-read (single), mark-all-as-read
> **Contract status:** `draft`
> **Frontend spec:** `../frontend/specs/012-personal-workspace` (notifications center — confirm pairing)
> **Author:** bwazik
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

- [ ] `notifications` table in tenant DB follows the Laravel notification convention and includes: `id`, `user_id`, `type` (notification class name), `notifiable_type`, `notifiable_id`, `data` (JSONB payload), `read_at` (nullable), `created_at`, `updated_at`
- [ ] A `public_id` (UUID v7, unique) column is added so notifications are addressable via API without exposing internal `id`
- [ ] `notifiable_type` / `notifiable_id` are polymorphic and reference `task`, `stage_instance`, or `escalation`
- [ ] `data` payload stores at minimum: `title_ar`, `title_en`, `body_ar`, `body_en`, `action_url`, `task_public_id`, and relevant stage/sub-stage/escalation public IDs
- [ ] Indexes exist on `(user_id, read_at)` and `(notifiable_type, notifiable_id)`
- [ ] The Notification module never writes to `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `escalations`, `sla_timer_instances`, `users`, or any Organization/IAM table

### Event Consumption & Recipient Resolution

- [ ] Listener creates an **assignment received** notification for each new assignee on `StageAssignmentCreated` (stage and sub-stage), excluding the actor where appropriate
- [ ] Listener creates a **task returned** notification for the assignees of the return-target stage on `StageInstanceReturned`
- [ ] Listener creates a **task advanced** confirmation notification for the assignees who completed the prior stage on `StageInstanceAdvanced`
- [ ] Listener creates an **SLA warning** notification for active assignees on `SlaWarningTriggered`
- [ ] Listener creates an **SLA breach** notification for active assignees and the resolved manager(s) on `SlaBreached`
- [ ] Listener creates an **escalation received** notification for the escalation target user on `EscalationCreated`
- [ ] Listener creates a **task completed** notification for the task initiator on `TaskCompleted`
- [ ] Listener creates a **task cancelled** notification for all active assignees and the initiator on `TaskCancelled`
- [ ] Listener creates a **task suspended** notification (including the suspension reason) for all active assignees and the initiator on `TaskSuspended`
- [ ] Listener creates a **task resumed** notification for all active assignees and the initiator on `TaskResumed`
- [ ] Recipient resolution reads recipient user public IDs carried in the consumed event payloads; where the event does not carry recipients, the listener resolves them via a read-only service call to the owning module (no cross-module ORM joins)
- [ ] A user is never notified about their own triggering action where the notification would be redundant (e.g., the user who advanced a stage still receives the advance confirmation, but is not double-notified as a new assignee for the same action)
- [ ] All listeners are idempotent: replaying the same event does not create duplicate notifications for the same recipient and event

### Channels & Localization

- [ ] Each notification is delivered to two channels: in-app (persisted `notifications` row) and email (queued mail)
- [ ] Email and in-app content render in the recipient's `users.preferred_language` (1 = Arabic, 2 = English)
- [ ] Arabic content is always present; English falls back to Arabic when an English string is unavailable
- [ ] Email delivery failures do not prevent the in-app notification from persisting
- [ ] No notification content leaks confidential task content beyond what the recipient is authorized to see; payloads for confidential tasks contain only the minimum metadata needed to act

### APIs

- [ ] `GET /api/v1/notifications` — cursor-paginated list of the authenticated user's notifications, newest first; supports `read` filter (`unread`, `read`, `all`, default `all`). Returns only the caller's own notifications.
- [ ] `GET /api/v1/notifications/unread-count` — returns the authenticated user's unread notification count
- [ ] `POST /api/v1/notifications/{notification}/read` — marks a single notification (by `public_id`) as read; 404 if it does not belong to the caller
- [ ] `POST /api/v1/notifications/read-all` — marks all of the caller's unread notifications as read
- [ ] All endpoints require authentication and the `X-Tenant` header; a user can only ever read or mutate their own notifications
- [ ] All responses use API Resources and expose `public_id` only; internal `id` is never returned
- [ ] List endpoint uses cursor pagination and returns `{data, next_cursor, has_more}`

### Domain Events

- [ ] New Notification events (e.g. `NotificationCreated`) implement `ShouldDispatchAfterCommit`
- [ ] Notification creation does not emit events that the Notification module itself consumes (no event loops)
- [ ] Audit (Spec 015) may later consume Notification events; payloads include `tenant_slug`, recipient user public ID, notification public ID, and source event type

### General

- [ ] All Notification data lives in the tenant DB; no `tenant_id` columns are added
- [ ] Redis/cache keys, if used, are tenant-prefixed
- [ ] All listeners, jobs, and service methods emit structured logs through the `notification` logging channel
- [ ] Feature tests cover: assignment notification, return notification, advance confirmation, SLA warning, SLA breach (assignees + manager), escalation received, task completed/cancelled/suspended/resumed, language selection, list with read filter, unread count, mark single read, mark all read, ABAC isolation (user cannot read another user's notifications), cursor pagination, and idempotent re-delivery

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

## Open Questions

- [ ] Frontend pairing: confirm whether the notifications center belongs to `../frontend/specs/012-personal-workspace` or a dedicated frontend spec. (Recommended: pair with the personal-workspace frontend spec.)
- [ ] Recipient carrying vs resolution: should every consumed event already carry resolved recipient user public IDs, or should the Notification listeners resolve recipients via read-only service calls? (Recommended: prefer event-carried recipients where Spec 005/006/007 already include them; fall back to a read-only service call for task initiator and active assignees where not carried.)
- [ ] Manager resolution on SLA breach: reuse the same target resolution already implemented in `SlaEscalationService` (Blueprint `escalation_position_id` → `reports_to_position_id`) by notifying the escalation target created in Spec 007, rather than re-resolving managers in the Notification module. (Recommended: notify the escalation target from the `EscalationCreated` event; do not re-resolve.)
- [ ] Email transport in MVP: confirm SMTP config is provisioned per environment and whether a tenant-level "from" address/branding is required. (Recommended: use platform default SMTP `from` for MVP; tenant branding deferred.)
- [ ] Should marking-read be allowed in bulk by passing an array of `public_id`s in addition to `read-all`? (Recommended: ship single read + read-all only for MVP.)
- [ ] Retention: do read notifications need an automatic prune/retention window, or are they kept indefinitely until archive policy is defined? (Recommended: keep indefinitely for MVP; revisit with archive/retention spec.)

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.
