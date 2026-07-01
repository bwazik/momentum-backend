# Spec: Delegation & Out-of-Office Supplement

> **Number:** 016
> **Date:** 2026-07-01
> **Status:** `completed`
> **Milestone:** M2 — Organization & IAM
> **Depends on:** `003-iam-abac` (base delegation CRUD, out-of-office toggle, ABAC policy engine), `004-blueprint-engine` (blueprint categories, stage types)
> **Provides APIs:** `GET /api/v1/iam/delegations/active`, `PUT /api/v1/iam/delegations/{delegation}`, `active_now` filter on `GET /api/v1/iam/delegations`, internal assignment-resolution integration
> **Contract status:** `stable`
> **Frontend spec:** `—` (backend-only)
> **Author:** Momentum init
> **Branch:** `feat/016-delegation-oof`
> **Base branch:** `main`

---

## Problem

Spec `003-iam-abac` introduced the `delegations` table, basic delegation CRUD, and the out-of-office (OOF) toggle, but left several MVP delegation behaviors incomplete:

1. **Scoped delegations are inert.** The `delegations` table stores `scope_type`, `blueprint_category_id`, and `stage_type_id`, yet `AssignmentResolutionService` only consults `IamPolicy::resolveAssignee()`, which resolves the simple OOF delegate on the `users` table. Delegations scoped to a blueprint category or stage type never affect assignment routing.
2. **Expired delegations stay active.** There is no scheduled process to deactivate delegations whose `ends_at` has passed, so the "active delegations" view becomes stale.
3. **No organization-wide active delegation view.** Managers and follow-up specialists cannot see, at a glance, who is currently covering whom across the organization.
4. **Scope fields are not validated.** `StoreDelegationRequest` accepts `blueprint_category_id` and `stage_type_id` as plain integers with no existence or consistency checks against Blueprint data.

Without this supplement, an out-of-office user who scoped a delegation to "Legal Review stages only" would still have *all* assignments routed to the simple OOF delegate (or none, if no simple delegate is set), and expired delegations would mislead the organization.

---

## Goal

Complete the Delegation & Out-of-Office subsystem so that:

- Scoped delegation rules (all, blueprint category, stage type, or both) are evaluated at task assignment time and route matching work to the correct delegate.
- Expired delegations are automatically deactivated by a scheduled job.
- Authorized users can list all currently active delegations in the organization.
- Delegation create/update requests validate scope fields against existing blueprint categories and stage types.
- Assignment records continue to store `delegated_from_user_id` so downstream UIs and audit trails can show that an assignment was routed via delegation.

This spec builds on `003` data structures and keeps all changes inside the IAM module boundary, except for the consumer-side call in `AssignmentResolutionService`.

---

## User Stories

### Scoped Delegation Routing

- As a **user going out of office**, I want to delegate only tasks of a specific blueprint category or stage type to a colleague, so that unrelated work remains assigned to me.
- As a **task initiator**, I want the system to automatically route a stage/sub-stage assignment to the active delegate when the intended assignee has a matching delegation, so that work is not blocked by absence.
- As a **delegate**, I want to see that an assignment was delegated from another user, so that I understand the context of the work.

### Auto-Expiry

- As a **tenant admin**, I want delegations that pass their end date to deactivate automatically, so that the delegation registry stays accurate without manual cleanup.
- As a **delegator returning from leave**, I want my delegation to end automatically at the scheduled time, so that new assignments resume routing to me.

### Organization Visibility

- As a **manager**, I want to see all currently active delegations in the organization, so that I know who is acting on whose behalf.
- As a **follow-up specialist**, I want to filter active delegations by delegator or delegate, so that I can trace accountability during an absence period.

---

## Acceptance Criteria

### Scoped Delegation Resolution

- [x] `IamPolicy` exposes a new method `resolveDelegateForAssignment(User $user, ?int $blueprintCategoryId, ?int $stageTypeId): ?User` that returns the matching delegate when an active delegation covers the given task context.
- [x] Matching logic honors `DelegationScopeType`:
- [x]   - `ALL` — any assignment matches.
- [x]   - `BLUEPRINT_CATEGORY` — matches when the task's blueprint category equals `blueprint_category_id`.
- [x]   - `STAGE_TYPE` — matches when the active stage/sub-stage type equals `stage_type_id`.
- [x]   - `BLUEPRINT_CATEGORY_AND_STAGE_TYPE` — matches only when **both** IDs equal the task context.
- [x] When multiple active delegations could match, the most recently created delegation wins (consistent with `003` "most recent wins" behavior).
- [x] If no scoped delegation matches, `IamPolicy` falls back to the simple OOF delegate (`out_of_office_delegate_user_id`) when the user is marked out-of-office.
- [x] If neither a scoped delegation nor a simple OOF delegate applies, the original assignee is returned.
- [x] `AssignmentResolutionService` calls `resolveDelegateForAssignment()` for each resolved user, passing the task's `blueprint_category_id` and the current stage/sub-stage `stage_type_id`.
- [x] For sub-stage assignments, `stage_type_id` is taken from the parent `BlueprintStage` because `blueprint_sub_stages` does not store a `stage_type_id`.
- [x] `task_stage_assignments.delegated_from_user_id` is populated whenever assignment routing was affected by a scoped delegation or simple OOF delegate.

### Delegation Scope Validation

- [x] `StoreDelegationRequest` validates that `blueprint_category_id` is required when `scope_type` is `BLUEPRINT_CATEGORY` or `BLUEPRINT_CATEGORY_AND_STAGE_TYPE`, and that the value references an existing `blueprint_categories.public_id`.
- [x] `StoreDelegationRequest` validates that `stage_type_id` is required when `scope_type` is `STAGE_TYPE` or `BLUEPRINT_CATEGORY_AND_STAGE_TYPE`, and that the value references an existing `stage_types.public_id`.
- [x] When `scope_type` is `ALL`, both `blueprint_category_id` and `stage_type_id` must be absent (or ignored).
- [x] `UpdateDelegationRequest` applies the same conditional validation rules if either field is present.

### Auto-Expiry

- [x] New scheduled command `iam:expire-delegations` scans `delegations` where `is_active = true` and `ends_at < now()` and deactivates them.
- [x] The command runs inside `DB::transaction()` and emits a `DelegationExpired` domain event for each deactivated delegation.
- [x] The command is registered in the scheduler to run every minute during business hours (configurable).
- [x] A queued `ExpireDelegationsJob` is available for manual dispatch or scheduler use, carrying tenant context.

### Active Delegations Endpoint

- [x] `GET /api/v1/iam/delegations/active` returns all delegations that are `is_active = true` and whose current time falls between `starts_at` and `ends_at`.
- [x] Endpoint supports filters: `delegator_user_id` (public_id), `delegate_user_id` (public_id), `blueprint_category_id` (public_id), `stage_type_id` (public_id).
- [x] Endpoint uses cursor pagination ordered by `id` ascending (with `{data, next_cursor, has_more}` shape).
- [x] Endpoint requires `iam.manage_users` or `iam.view_delegations` capability.
- [x] Response includes delegator, delegate, scope fields, and expiry timestamps via `DelegationResource`.

### Existing Endpoints

- [x] `GET /api/v1/iam/delegations` remains available; a new boolean filter `active_now` is added to restrict results to currently active delegations (cursor-paginated when active_now=true).
- [x] `POST /api/v1/iam/delegations/{delegation}/revoke` remains available and is unchanged except for capability check alignment.

### Events & Audit

- [x] New domain event `DelegationExpired` implements `ShouldDispatchAfterCommit` and `ProvidesAuditData`.
- [x] Audit module records `DelegationExpired` events with `entity_type = delegation`.

### Tests

- [x] Feature tests verify scoped delegation routing for all four scope types.
- [x] Feature tests verify fallback from scoped delegation → simple OOF delegate → original assignee.
- [x] Feature tests verify validation errors for missing/invalid `blueprint_category_id` or `stage_type_id`.
- [x] Feature tests verify the auto-expiry command deactivates expired delegations and leaves active ones untouched.
- [x] Feature tests verify `GET /api/v1/iam/delegations/active` returns only currently active delegations and respects filters.
- [x] Feature tests verify ABAC denial for users without `iam.manage_users` or `iam.view_delegations`.

---

## Non-Functional Requirements

> These requirements follow `docs/ai/coding-standards.md`. Read that file before creating `plan.md`.

### Pagination

- `GET /api/v1/iam/delegations/active` uses **cursor pagination** (expected > 1000 delegations per tenant in large organizations).
- `GET /api/v1/iam/delegations` with `active_now=true` also uses **cursor pagination**.
- Small reference lookups inside form requests (capabilities, categories, stage types) return full lists or single records as appropriate.
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`.

### Caching

- No response caching on delegation list endpoints; delegation state is time-sensitive and must reflect the current clock.
- `IamPolicy` per-request memory cache remains the primary performance mechanism for capability/delegation lookups within a single HTTP request.
- No Redis caching of delegation catalogs; invalidation complexity outweighs benefit for a write-heavy, time-bound table.

### Rate Limiting

- Delegation list endpoints (`GET /api/v1/iam/delegations`, `GET /api/v1/iam/delegations/active`): `RateLimits::LIST` (60/min per user).
- Delegation mutating endpoints (`POST /api/v1/iam/delegations`, `POST /api/v1/iam/delegations/{delegation}/revoke`): `RateLimits::MUTATE` (30/min per user).
- Out-of-office endpoints remain unchanged from `003`.

### Database Transactions

- Creating a delegation: already wrapped in `DB::transaction()` in `003`; no change required.
- Revoking a delegation: single update, no transaction required.
- Auto-expiry command: must wrap the scan-and-update loop in `DB::transaction()` per batch to ensure atomic deactivation.
- Assignment resolution: writes multiple `task_stage_assignments` rows in a loop; already handled by `AssignmentResolutionService` semantics in `005`.

### Error Handling & Logging

- Module logging channel: `iam`.
- All new service methods use try/catch with `Log::channel('iam')` and structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`.
- New domain exception `DelegationScopeMismatchException` (422) for invalid scope field combinations.
- Domain exceptions registered in `bootstrap/app.php`.

### Enums

- Reuse existing `App\Enums\DelegationScopeType` (created in `003`); no new enum required.
- All form requests validate `scope_type` via `Rule::enum(DelegationScopeType::class)`.
- All service logic compares against enum cases, never raw integers.

### Queue Jobs

- `ExpireDelegationsJob` dispatched by the scheduler; implements `ShouldQueue` with `public int $tries = 3` and `public array $backoff = [30, 60, 120]`.
- Job carries tenant slug so the worker switches to the correct tenant DB.
- Domain events (`DelegationExpired`) implement `ShouldDispatchAfterCommit`.

---

## Out of Scope

- **Delegation activity summary on return** (Feature Inventory #209, V2) — reviewing stage actions taken on a delegator's behalf is deferred.
- **Full delegation history UI** (Feature Inventory #210, V2) — long-term audit views of past delegations are deferred.
- **My delegation status** (Feature Inventory #219, V2) — personal workspace view of delegations given/received is deferred.
- **Delegation activity notifications** — while the Notification module exists, delegation-specific "delegator notified on action" alerts are V2.
- **Financial delegation thresholds** (Feature Inventory #10, V2) — approval authority limits per position are deferred.
- **Cross-tenant delegations** — delegations are always within a single tenant.
- **Delegate acceptance/decline flow** — delegations are effective immediately upon creation.

---

## Open Questions

- [x] **Precedence (scoped delegation vs. simple OOF):** Should scoped delegation or simple OOF delegate take priority? **Resolution:** Scoped delegation wins when it matches; otherwise fall back to simple OOF delegate; otherwise original assignee. Documented in `IamPolicy::resolveDelegateForAssignment()`.
- [x] **Overlapping scoped delegations:** Can a delegator have multiple active delegations with the same scope? **Resolution:** Yes, allowed. Most recently created active delegation wins at resolution time, matching existing `003` "most recent wins" behavior.
- [x] **Auto-expiry frequency:** How often should the expiry command run? **Resolution:** Every minute via scheduler, idempotent deactivation. One-minute granularity is sufficient for MVP without per-second polling.
- [x] **Expired delegation disposal:** Should expired delegations be soft-deleted or marked inactive? **Resolution:** Mark `is_active = false` only; never delete. Preserves audit history and enables future V2 delegation-history features.
- [x] **Read capability:** Should there be a dedicated capability for viewing delegations? **Resolution:** Add `iam.view_delegations` to the capability catalog; seed it for tenant admins. Keeps read access separate from `iam.manage_users` following least privilege.
- [x] **Sub-stage `stage_type_id` context:** Where does the `stage_type_id` come from for sub-stage assignments? **Resolution:** Sub-stages inherit `stage_type_id` from their parent `BlueprintStage` because `blueprint_sub_stages` has no `stage_type_id` column (confirmed in `004-blueprint-engine`). `AssignmentResolutionService` resolves it via `$blueprintSubStage->stage->stage_type_id`.
