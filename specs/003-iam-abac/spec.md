# Spec: IAM & ABAC

> **Number:** 003
> **Date:** 2026-06-09
> **Status:** `completed`
> **Milestone:** M2 — Organization & IAM
> **Depends on:** `001-platform-tenancy` (tenant DB provisioning, base models, tenant context resolution), `002-organization-structure` (departments, positions, authority grades)
> **Provides APIs:** User CRUD, authentication (login/logout), position assignment, capability catalog, position capability grants, user capability grants, monitoring scope grants, ABAC policy check endpoint, delegation CRUD, out-of-office toggle
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/009-system-administration`
> **Author:** Momentum init
> **Branch:** `feat/003-iam-abac`
> **Base branch:** `main`

---

## Problem

Gov TMS has no user management or authorization system. The existing `RequireTenantAdmin` middleware hardcodes `account_type === 2` — a single boolean gate that cannot express the nuanced access rules the platform requires. Without a proper IAM module:

- **No users exist** in tenant DBs — the `users` table is the default Laravel schema (no `public_id`, `name_ar`, `mobile`, `employee_id`, `is_active`).
- **No authentication** — no login/logout/password-reset endpoints; Sanctum is installed but not wired.
- **No position assignments** — users cannot be linked to the positions created in Spec 002.
- **No capabilities** — the ABAC policy engine described in the security policy has zero implementation.
- **No scoped visibility** — task visibility, department scope, and monitoring scope cannot be resolved.
- **No delegation** — out-of-office authority transfer is impossible.
- **No confidential access governance** — no mechanism to name or configure governance participants.

The platform cannot function without users who can authenticate, hold positions, receive capabilities, and delegate authority.

---

## Goal

Build the IAM module — user accounts with Sanctum authentication, position assignments, capability catalog, scoped capability grants (position-level and user-level), monitoring scope grants, ABAC policy engine, and delegations — so that downstream modules (Blueprint, Task, Tracking, Analytics) can resolve assignees, check permissions, and enforce task visibility rules.

All data lives in the tenant DB (no `tenant_id` columns). All mutating endpoints enforce ABAC checks via the policy engine. The existing `RequireTenantAdmin` middleware is replaced by proper capability-based authorization.

---

## User Stories

### Authentication

- As a **user**, I want to log in with my email and password, so that I can access the platform.
- As a **user**, I want to log out, so that my session is terminated.
- As a **tenant admin**, I want to create user accounts for people in my organization, so they can access the platform.
- As a **tenant admin**, I want to deactivate a user account (soft delete), so that the person can no longer log in but their historical data remains intact.
- As a **tenant admin**, I want to reactivate a previously deactivated user, so they can log in again.

### User Management

- As a **tenant admin**, I want to list users with pagination and filters (department, is_active, account_type), so I can manage a large organization.
- As a **tenant admin**, I want to view a user's profile including their current position assignment and capabilities, so I can audit their access.
- As a **tenant admin**, I want to update a user's profile (name, mobile, preferred language), so records stay current.
- As a **tenant admin**, I want to assign a position to a user, so the user inherits that position's capabilities.
- As a **tenant admin**, I want to end a user's position assignment, so they no longer hold that role.

### Position Assignment

- As a **tenant admin**, I want to assign a position to a user with a start date, so the system can track position history.
- As a **tenant admin**, I want to set one position as a user's primary assignment, so the system knows which department and authority grade define their default access.
- As an **authorized user**, I want to see the current occupant of a position, so I know who holds a role.

### Capabilities

- As a **tenant admin**, I want to view the full capability catalog, so I know what permissions exist.
- As a **tenant admin**, I want to grant a capability to a position with a scope (tenant-wide, own department, specific department, department tree), so everyone in that position inherits the capability within the chosen scope.
- As a **tenant admin**, I want to revoke a capability grant from a position, so the people filling that position lose that permission.
- As a **tenant admin**, I want to grant a capability directly to an individual user as an exception, providing a mandatory reason, so audit-sensitive overrides are tracked.
- As a **tenant admin**, I want to revoke a user-level capability grant, so exceptions can be removed.

### ABAC Policy Engine

- As a **system module** (Blueprint, Task, Tracking), I want to call `IamPolicy::check($user, 'task.view.organization')`, so I can authorize or deny an action based on capabilities and scope.
- As a **system module**, I want to check if a user has a capability scoped to a specific department, so department-level visibility rules are enforced.
- As a **system module**, I want to resolve the current delegate for a user during an active delegation period, so assignments route to the correct person.
- As a **tenant admin**, I want to see what capabilities a user effectively has (through their position + direct grants), so I can audit access.

### Monitoring Scopes

- As a **tenant admin**, I want to grant a user a monitoring scope (department + optional blueprint category), so they can see tasks in the follow-up board within that scope.
- As a **tenant admin**, I want to revoke a monitoring scope grant, so the user can no longer see those tasks.
- As a **tenant admin**, I want to list a user's active monitoring scopes, so I can review their follow-up access.

### Delegations

- As a **user going out-of-office**, I want to delegate my authority to another user for a time period, so my responsibilities continue in my absence.
- As a **user creating a delegation**, I want to scope it (all tasks, or specific blueprint category, or specific stage type), so I don't over-delegate.
- As a **tenant admin**, I want to see all active delegations, so I can audit who is acting on behalf of whom.
- As a **tenant admin**, I want to revoke a delegation, so the delegate no longer receives my assignments.

### Out-of-Office

- As a **user**, I want to mark myself as out-of-office, so the system knows I am unavailable and routes my assignments to my delegate.
- As a **user**, I want to mark myself as back-in-office, so assignments resume routing to me.

---

## Acceptance Criteria

### Users Table & Model

- [ ] `users` table in tenant DB is migrated to include: `public_id` (UUID v7, unique), `account_type` (TINYINT, default 1), `name_en`, `name_ar` (required), `mobile` (nullable), `employee_id` (nullable, unique), `preferred_language` (TINYINT, default 1), `is_active` (boolean, default true), `deleted_at` (soft delete), and existing columns (`email` unique, `password`, `remember_token`, `email_verified_at`, timestamps)
- [ ] `User` model extends `Authenticatable` (not `TenantModel`) but has `public_id` auto-generated via `Str::uuid7()` on create, `getRouteKeyName()` returning `'public_id'`
- [ ] `User` model includes `HasApiTokens` (Sanctum) trait
- [ ] `User` model casts: `account_type` → SmallInteger, `is_active` → boolean, `preferred_language` → SmallInteger, `password` → hashed, `email_verified_at` → datetime
- [ ] All API responses expose `public_id` only — never internal `id` or `password`
- [ ] Bilingual: `name_ar` required, `name_en` optional (falls back to `name_ar`)

### Authentication

- [ ] `POST /api/v1/iam/auth/login` — authenticates user by email + password; returns Sanctum token; validates `is_active = true` and `account_type !== 4` (platform admins log in via central, not tenant)
- [ ] `POST /api/v1/iam/auth/logout` — invalidates current Sanctum token; requires authentication
- [ ] Login rate-limited to 5 attempts per minute per email + IP combination
- [ ] Failed login returns generic "Invalid credentials" message (no user enumeration)
- [ ] Account soft-deletion (`deleted_at`) prevents login; deactivated accounts (`is_active = false`) also cannot log in

### User CRUD

- [ ] `GET /api/v1/iam/users` — list users with pagination and filters: `is_active`, `account_type`, `department_id` (via current primary position), `search` (name, email, employee_id). Requires `iam.manage_users` capability.
- [ ] `POST /api/v1/iam/users` — create user; `name_ar` required, `email` required and unique within tenant, `password` required (min 8 chars), `account_type` required (1=internal_user, 2=tenant_admin, 3=external_auditor). Requires `iam.manage_users`.
- [ ] `GET /api/v1/iam/users/{user}` — show user profile with current position assignment and effective capabilities. Authenticated users can view own profile; `iam.manage_users` required for others.
- [ ] `PUT /api/v1/iam/users/{user}` — update user profile (name, mobile, preferred_language, account_type). `iam.manage_users` required; users can update own name/mobile/language.
- [ ] `POST /api/v1/iam/users/{user}/deactivate` — soft-delete user; sets `is_active = false` and `deleted_at`. Requires `iam.manage_users`.
- [ ] `POST /api/v1/iam/users/{user}/reactivate` — restore deactivated user; sets `is_active = true` and clears `deleted_at`. Requires `iam.manage_users`.

### Position Assignments

- [ ] `user_position_assignments` table in tenant DB with columns: `id`, `user_id` (FK users), `position_id` (FK positions), `started_at` (timestamp), `ended_at` (nullable timestamp), `is_primary` (boolean, default true), `created_at`
- [ ] `POST /api/v1/iam/users/{user}/positions` — assign a position to a user with `started_at`. `position_id` (by public_id) must reference an active position. Requires `iam.manage_positions`.
- [ ] `POST /api/v1/iam/users/{user}/positions/{assignment}/end` — end an assignment; sets `ended_at = now()`. If it was the primary assignment, the user has no primary position until a new one is assigned. Requires `iam.manage_positions`.
- [ ] `POST /api/v1/iam/users/{user}/positions/{assignment}/set-primary` — marks an active assignment as primary; unsets any other primary for the same user. Requires `iam.manage_positions`.
- [ ] MVP enforces: one active primary position per user. Attempting to assign a second primary must either auto-end the previous primary or reject the request.
- [ ] The `Position.currentOccupant()` relationship (commented out in Spec 002) is uncommented and activated, referencing `UserPositionAssignment` where `ended_at IS NULL` and `is_primary = true`.
- [ ] `UserResource` includes `current_position` with position details and department context.

### Capabilities

- [ ] `capabilities` table in tenant DB with columns: `id`, `key` (varchar, unique), `name_en`, `name_ar` (required), `description` (nullable), `is_system_defined` (boolean, default true), `created_at`, `updated_at`
- [ ] System-defined capabilities (25 MVP capabilities from `04_Visibility_Access_Rules.md` Section 5) are seeded on tenant provisioning. These cannot be deleted; `name_ar`/`name_en`/`description` can be updated by `iam.manage_capabilities`.
- [ ] `GET /api/v1/iam/capabilities` — list all capabilities (unpaginated, expected < 50). Authenticated users can view the catalog.
- [ ] `PUT /api/v1/iam/capabilities/{capability}` — update name/description of a capability. System-defined keys cannot be changed. Requires `iam.manage_capabilities`.

### Position Capability Grants

- [ ] `position_capability_grants` table in tenant DB with columns: `id`, `position_id` (FK positions), `capability_id` (FK capabilities), `scope_type` (TINYINT: 1=tenant, 2=own_department, 3=specific_department, 4=department_tree, 5=own_tasks), `scope_department_id` (nullable FK departments, required when scope_type is 3 or 4), `granted_by_user_id` (FK users), `granted_at` (timestamp), `revoked_at` (nullable timestamp)
- [ ] `POST /api/v1/iam/positions/{position}/capabilities` — grant a capability to a position with a scope. `revoked_at` is null (active grant). Requires `iam.manage_capabilities`.
- [ ] `GET /api/v1/iam/positions/{position}/capabilities` — list active (non-revoked) capability grants for a position. Requires `iam.manage_capabilities` or `iam.manage_positions`.
- [ ] `POST /api/v1/iam/position-capability-grants/{grant}/revoke` — set `revoked_at = now()` to deactivate a grant. Does not delete the row (full audit trail). Requires `iam.manage_capabilities`.
- [ ] When a position is deactivated, all its active capability grants remain active (they may become inert because no user holds the position, but they are not auto-revoked).

### User Capability Grants

- [ ] `user_capability_grants` table in tenant DB with columns: `id`, `user_id` (FK users), `capability_id` (FK capabilities), `scope_type` (TINYINT, same values as position grants), `scope_department_id` (nullable FK departments), `granted_by_user_id` (FK users), `granted_at` (timestamp), `revoked_at` (nullable), `reason` (required TEXT — mandatory justification for user-level exception grants)
- [ ] `POST /api/v1/iam/users/{user}/capabilities` — grant a capability directly to a user with a scope and mandatory `reason`. Requires `iam.manage_capabilities`.
- [ ] `GET /api/v1/iam/users/{user}/capabilities` — list active capability grants for a user (both position-derived and direct). Requires `iam.manage_capabilities` or self-view.
- [ ] `POST /api/v1/iam/user-capability-grants/{grant}/revoke` — set `revoked_at = now()`. Does not delete the row. Requires `iam.manage_capabilities`.

### ABAC Policy Engine

- [ ] `IamPolicy` service class in `App\Modules\Iam\Services\` with a `check(User $user, string $capability, ?string $scopeType = null, ?int $departmentId = null): bool` method
- [ ] `IamPolicy::check` resolves effective capabilities by union of: (a) all active position capability grants for the user's current primary position, (b) all active user-level capability grants
- [ ] If `scopeType` and `departmentId` are provided, the check also verifies the grant's scope covers the given department
- [ ] Scope resolution logic:
  - `tenant` → always allows (no department restriction)
  - `own_department` → allows for the user's current primary department
  - `specific_department` → allows only if `scope_department_id === $departmentId`
  - `department_tree` → allows if `$departmentId` is the scope department or any descendant in the department tree
  - `own_tasks` → allows only for tasks where user is initiator, current/past assignee, or named participant; no department restriction (task-level check done by caller)
- [ ] `IamPolicy::getEffectiveCapabilities(User $user): Collection` — returns all active capability keys merged from position grants and user grants, with scope info
- [ ] Platform admin (`account_type = 4`) cannot access tenant APIs; tenant admin (`account_type = 2`) implicitly has all capabilities within their tenant for admin functions (but this is a separate check from ABAC — tenant admin access is technical, not capability-based, and is audit-logged)

### Monitoring Scopes

- [ ] `monitoring_scope_grants` table in tenant DB with columns: `id`, `user_id` (FK users), `scope_type` (TINYINT: 1=tenant, 2=own_department, 3=specific_department, 4=department_tree), `scope_department_id` (nullable FK departments), `blueprint_category_id` (nullable, FK to blueprint_categories — deferred until Spec 004), `granted_by_user_id` (FK users), `granted_at` (timestamp), `revoked_at` (nullable)
- [ ] `POST /api/v1/iam/users/{user}/monitoring-scopes` — grant a monitoring scope to a user. Requires `iam.manage_capabilities`.
- [ ] `GET /api/v1/iam/users/{user}/monitoring-scopes` — list active monitoring scope grants for a user. Authenticated view or `iam.manage_capabilities`.
- [ ] `POST /api/v1/iam/monitoring-scope-grants/{grant}/revoke` — revoke a monitoring scope grant. Requires `iam.manage_capabilities`.

### Audit Grants

- [ ] `audit_grants` table in tenant DB with columns: `id`, `external_auditor_user_id` (FK users, must be `external_auditor` account), `granted_by_user_id` (FK users), `date_range_start` (date), `date_range_end` (date), `department_id` (nullable FK departments), `granted_at` (timestamp), `revoked_at` (nullable)
- [ ] `POST /api/v1/iam/users/{user}/audit-grants` — grant an audit scope to an external auditor with `date_range_start`, `date_range_end`, and optional `department_id`. Requires `iam.manage_capabilities`.
- [ ] `GET /api/v1/iam/users/{user}/audit-grants` — list active audit grants for a user. Requires `iam.manage_capabilities`.
- [ ] `POST /api/v1/iam/audit-grants/{grant}/revoke` — revoke an audit grant. Requires `iam.manage_capabilities`.
- [ ] `IamPolicy::checkAuditGrant(User $auditor, ?int $departmentId): bool` — returns true if the auditor has an active, non-revoked grant whose date range covers today and whose department scope (if set) covers the given department.
- [ ] `User` model has `auditGrants()` relationship — `$this->hasMany(AuditGrant::class, 'external_auditor_user_id')`.

### Delegations

- [ ] `delegations` table in tenant DB with columns: `id`, `public_id` (UUID v7), `delegator_user_id` (FK users), `delegate_user_id` (FK users), `starts_at` (timestamp), `ends_at` (timestamp), `scope_type` (TINYINT: 1=all, 2=blueprint_category, 3=stage_type, 4=blueprint_category_and_stage_type), `blueprint_category_id` (nullable), `stage_type_id` (nullable), `is_active` (boolean, default true), `created_at`, `updated_at`
- [ ] `POST /api/v1/iam/delegations` — create a delegation. Delegator must be the authenticated user or `iam.manage_users`. `delegate_user_id` cannot be the same as `delegator_user_id`. `ends_at` must be after `starts_at`. Requires `iam.manage_users`.
- [ ] `GET /api/v1/iam/delegations` — list delegations. Filters: `is_active`, `delegator_user_id`, `delegate_user_id`. Requires `iam.manage_users` or self-view.
- [ ] `POST /api/v1/iam/delegations/{delegation}/revoke` — set `is_active = false`. Requires `iam.manage_users` or delegation's `delegator_user_id` matching auth user.
- [ ] `IamPolicy::getActiveDelegate(User $user): ?User` — returns the delegate user if the given user has an active delegation (where `now()` is between `starts_at` and `ends_at`, `is_active = true`). If multiple active delegations exist, the most recently created one wins.
- [ ] Delegation scope references (`blueprint_category_id`, `stage_type_id`) are FK placeholders that will be validated when their respective modules (Specs 004, 006) are built. For MVP, `scope_type = 1` (all) is the primary use case.

### Out-of-Office

- [ ] `users` table gains `is_out_of_office` (boolean, default false) and `out_of_office_delegate_user_id` (nullable FK users) columns via migration.
- [ ] `POST /api/v1/iam/users/{user}/out-of-office` — set `is_out_of_office = true` and optionally set `out_of_office_delegate_user_id`. Authenticated user can set own; `iam.manage_users` can set for others.
- [ ] `POST /api/v1/iam/users/{user}/back-in-office` — set `is_out_of_office = false` and clear `out_of_office_delegate_user_id`.
- [ ] `IamPolicy::isOutOfOffice(User $user): bool` — quick check if user is marked OOO.
- [ ] `IamPolicy::resolveAssignee(User $user): User` — if user is OOO, returns their designated delegate; otherwise returns the user. Used by task stage assignment resolution.

### Replace RequireTenantAdmin Middleware

- [ ] `RequireTenantAdmin` middleware is removed from all routes.
- [ ] New `RequireCapability` middleware is created: `RequireCapability::class` takes a capability key (e.g., `iam.manage_users`) and uses `IamPolicy::check()` to authorize.
- [ ] Tenant admins (`account_type = 2`) are seeded with all `iam.manage_*` capabilities via position grant in tenant provisioning.
- [ ] All Organization module mutating routes updated from `RequireTenantAdmin` to `RequireCapability:organization.manage`.
- [ ] All IAM module mutating routes use appropriate `RequireCapability` middleware.

### General

- [ ] All endpoints follow `/api/v1/iam/` prefix
- [ ] All responses use API Resources with `public_id` only — never expose internal `id`
- [ ] Bilingual fields: `name_ar` always required; `name_en` optional, falls back to `name_ar`
- [ ] Domain events emitted for all mutating actions (user created, logged in/out, position assigned/ended/primary-changed, capability granted/revoked, delegation created/revoked, OOO toggled)
- [ ] Custom exceptions: `UserAlreadyActiveException`, `UserAlreadyDeactivatedException`, `CannotDelegateToSelfException`, `DelegationConflictException`, `PrimaryPositionAlreadyAssignedException`, `CannotRevokeSystemCapabilityKeyException`
- [ ] Feature tests cover: user CRUD, login/logout, position assignment (single primary), capability grant/revoke cycle, ABAC policy check (position grant, user grant, scope resolution), delegation creation/revoke, OOO toggle, scope tree resolution

---

## Out of Scope

- **Platform admin authentication** — platform admins (`account_type = 4`) authenticate against central DB, not tenant DB. Spec 001 handles platform admin login.
- **Password reset / forgot password** — deferred to post-MVP. Tenant admin can set a new password for a user.
- **Email verification flow** — MVP creates users with `email_verified_at` set automatically. Verification emails deferred.
- **SSO / LDAP integration** — not MVP; architecture must allow per-tenant SSO later.
- **Multiple concurrent positions** — MVP enforces one active primary position per user. Historical (ended) assignments are preserved.
- **Confidential task access** — `confidential_governance_participants`, `confidential_access_events`, and `task_confidential_participants` tables are out of scope; they belong to the Task module (Specs 005/017).
- ~~**Audit grants** — `audit_grants` and `external_auditor` access rules are out of scope; they belong with the Audit module (Spec 015).~~ **Reclassified:** `audit_grants` is now in-scope for IAM (see acceptance criteria). The table is structurally identical to other IAM grant tables (`MonitoringScopeGrant`, `UserCapabilityGrant`) and the ABAC engine must check it to make the `EXTERNAL_AUDITOR(3)` account type functional.
- **Full audit trail persistence** — Spec 015 will consume domain events. This spec emits events only.
- **Blueprint categories and stage types** — referenced as FK placeholders in delegations; actual validation deferred to Specs 004/006.
- **Task visibility rules** — the ABAC policy engine provides capability checks and scope resolution, but actual `view_task` enforcement is in the Task module (Spec 005).
- **Analytics access rules** — enforced when Analytics module is built (Spec 009).
- **Onboarding journey detection** — deferred to Spec 019.

---

## Open Questions

- [ ] Should login use Sanctum SPA cookies (session-based) or token-based API tokens? **Recommendation:** SPA cookies with CSRF for frontend-only consumption; API tokens for programmatic access. MVP: SPA cookies only.
- [ ] Should `user_capability_grants.reason` be a required field even for re-grants? **Recommendation:** Yes — every direct user grant must have a reason for audit.
- [ ] Should revoking a capability grant delete the row or set `revoked_at`? **Recommendation:** Set `revoked_at` (soft revoke). Existing grants are never hard-deleted; re-granting creates a new row with a new `granted_at`. This preserves the full audit trail.
- [ ] Should position deactivation cascade-revoke all grants referencing that position? **Recommendation:** No — grants remain active but become inert (no user holds the position, so no one inherits the capability). Manual revocation by admin.
- [ ] Should there be a "grant all capabilities" shortcut for tenant admin setup during provisioning? **Recommendation:** Yes — provisioning seed script assigns all `iam.manage_*` capabilities to the initial tenant admin's position as 
- [ ] Should `monitoring_scope_grants.blueprint_category_id` be validated now or left as a nullable FK placeholder? **Recommendation:** Nullable FK placeholder. Blueprint categories don't exist yet (Spec 004). Add the FK constraint in a later migration when `blueprint_categories` table exists.
- [ ] Should out-of-office be a simple boolean toggle on the user, or should it be modeled as a special type of delegation? **Recommendation:** Simple boolean + `out_of_office_delegate_user_id` on the `users` table. It's the most common use case and doesn't need a separate table. The `delegations` table handles explicit scoped delegations.
- [ ] Should `IamPolicy::check()` cache capability lookups? **Recommendation:** Yes, per-request cache using a memory singleton. Clear at request end. Avoid N+1 on multi-check endpoints.
