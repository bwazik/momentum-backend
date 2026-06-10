# Spec: Platform Administration

> **Number:** 001-platform-admin
> **Date:** 2026-06-10
> **Status:** `completed`
> **Milestone:** M1 — Platform & Core Foundation
> **Depends on:** `001-platform-tenancy` (central tenant registry, DB provisioning, tenant context resolution, base models)
> **Provides APIs:** Platform admin authentication (central DB), tenant CRUD (list, show, update, suspend, reactivate), impersonation with audit trail, platform admin user management
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/009-system-administration` (partial — platform admin section)
> **Author:** Momentum init
> **Branch:** `feat/001-platform-admin`
> **Base branch:** `main`

---

## Problem

The existing platform tenancy foundation (Spec 001) provides tenant provisioning, DB switching middleware, and a scaffolded `ImpersonationService` — but no API endpoints, no platform admin authentication, and no management interface for the tenant lifecycle. Without this spec:

- **No platform admin authentication** — `account_type = 4` (platform_admin) is explicitly blocked from tenant login, but there is no central login endpoint. Platform admins have no way to authenticate.
- **No tenant CRUD API** — `TenantProvisioningService::provision()` exists but has no controller or route. There is no way to list, show, update, suspend, or reactivate tenants via the API.
- **No impersonation API** — `ImpersonationService` exists but is not wired to any endpoint. Platform admins cannot impersonate tenant users for support.
- **No audit trail for platform actions** — Provisioning, suspension, and impersonation initiation happen without any audit log entry in the central DB.

Platform admins operate exclusively on the central database. They never access tenant databases directly (only via impersonation). This spec fills the gap between "infrastructure exists" and "platform admins can actually use it."

---

## Goal

Build the Platform Admin module — central authentication, tenant lifecycle management API, impersonation with audit trail, and platform admin user management — so that platform operators can authenticate, manage tenants, and support tenant users without passwords or backdoors.

All data lives in the **central database**. All endpoints are under `/api/v1/platform/`. No tenant context switching occurs for platform admin endpoints (they use the central connection directly).

---

## User Stories

### Platform Admin Authentication

- As a **platform admin**, I want to log in with my email and password against the central database, so that I can access the platform admin API.
- As a **platform admin**, I want to receive a Sanctum token upon successful authentication, so I can make authenticated API calls.
- As a **platform admin**, I want to log out, so that my token is invalidated.
- As a **platform admin**, I want my login to be rate-limited, so that brute-force attacks are prevented.

### Platform Admin Management

- As a **platform admin**, I want to create another platform admin account, so that my team can grow.
- As a **platform admin**, I want to revoke (soft-delete) a platform admin account, so that departing team members lose access.
- As a **platform admin**, I want to list all platform admins, so I can see who has access.
- As a **platform admin**, I want to update a platform admin's profile (name, email), so records stay current.

### Tenant Lifecycle Management

- As a **platform admin**, I want to provision a new tenant (name, slug, domain, timezone, language), so that a new organization can onboard to Gov TMS.
- As a **platform admin**, I want to list all tenants with cursor pagination and filters (is_active, search by name/slug), so I can manage a growing platform.
- As a **platform admin**, I want to view a tenant's full details including database status, so I can troubleshoot issues.
- As a **platform admin**, I want to update a tenant's profile (name, domain, timezone, logo, settings), so branding and configuration stay current.
- As a **platform admin**, I want to suspend a tenant (set `is_active = false`), so that all tenant users are locked out while data is preserved.
- As a **platform admin**, I want to reactivate a suspended tenant, so it can resume operations.
- As a **platform admin**, I want to run tenant migrations, so that a tenant's database schema stays up to date after a platform deploy.

### Impersonation

- As a **platform admin**, I want to initiate an impersonation session for a user in a specific tenant, so I can troubleshoot on their behalf.
- As a **platform admin**, I want the impersonation initiation to be logged in the central `audit_events` table with my identity, the target user, the tenant, and the timestamp, so there is an immutable audit trail.
- As a **platform admin**, I want to end my impersonation session, so that I return to my platform admin identity.
- As a **platform admin**, I want to see who is currently impersonating whom, so I can audit active impersonation sessions.

---

## Acceptance Criteria

### Central Authentication

- [x] `POST /api/v1/platform/auth/login` — authenticates platform admin by email + password against the **central database**. Only `account_type = 4` (platform_admin) users can authenticate. Returns a Sanctum token. Validates `is_active = true`.
- [x] `POST /api/v1/platform/auth/logout` — invalidates the current Sanctum token. Requires authentication.
- [x] `GET /api/v1/platform/auth/me` — returns the authenticated platform admin's profile.
- [x] Login rate-limited to 5 attempts per minute per email + IP combination using `RateLimits::AUTH_LOGIN`.
- [x] Failed login returns generic "Invalid credentials" message (no user enumeration).
- [x] The `users` table in the **central database** includes platform admin accounts with `account_type = 4` and `is_active`. These accounts are completely separate from tenant user accounts.
- [x] Password hashing uses bcrypt. Minimum 8 characters.

### Platform Admin CRUD

- [x] `POST /api/v1/platform/admins` — create a platform admin. `name_ar` required, `email` required and unique in central DB, `password` required (min 8 chars). Only existing platform admins can create new ones.
- [x] `GET /api/v1/platform/admins` — list platform admins with cursor pagination. Searchable by name, email.
- [x] `GET /api/v1/platform/admins/{admin}` — show a platform admin's profile.
- [x] `PUT /api/v1/platform/admins/{admin}` — update profile (name, email).
- [x] `POST /api/v1/platform/admins/{admin}/deactivate` — soft-delete a platform admin (sets `is_active = false`, `deleted_at`). A platform admin cannot deactivate themselves.
- [x] `POST /api/v1/platform/admins/{admin}/reactivate` — restore a deactivated platform admin.
- [x] All platform admin endpoints require `auth:sanctum` + `account_type = 4` middleware.
- [x] All responses use `public_id` only — never expose internal `id` or `password`.

### Tenant CRUD

- [x] `POST /api/v1/platform/tenants` — provision a new tenant. Wraps `TenantProvisioningService::provision()`. Creates central DB row + tenant database. Wraps in `DB::transaction()`. Logs to `audit_events`. Rate-limited to `RateLimits::MUTATE`.
- [x] `GET /api/v1/platform/tenants` — list tenants with cursor pagination. Filters: `is_active`, `search` (name, slug). Rate-limited to `RateLimits::LIST`.
- [x] `GET /api/v1/platform/tenants/{tenant}` — show tenant details. Includes: `public_id`, `name_en`, `name_ar`, `slug`, `domain`, `database_name`, `is_active`, `default_language`, `timezone`, `logo_path`, `settings`, `created_at`, `updated_at`.
- [x] `PUT /api/v1/platform/tenants/{tenant}` — update tenant profile (name, domain, timezone, logo, settings, default_language). Cannot change `slug` or `database_name`. Wraps in `DB::transaction()`. Logs to `audit_events`.
- [x] `POST /api/v1/platform/tenants/{tenant}/suspend` — set `is_active = false`. The `CheckTenantStatus` middleware will block all tenant API requests for this tenant. Logs to `audit_events`.
- [x] `POST /api/v1/platform/tenants/{tenant}/reactivate` — set `is_active = true`. Logs to `audit_events`.
- [x] `POST /api/v1/platform/tenants/{tenant}/run-migrations` — run pending tenant migrations against the tenant database. Logs to `audit_events`.
- [x] All tenant endpoints require `auth:sanctum` + `account_type = 4` middleware.
- [x] All responses use `public_id` for tenant identification — route model binding by `public_id`.

### Impersonation

- [x] `POST /api/v1/platform/tenants/{tenant}/impersonate` — initiate impersonation. Takes `user_public_id` (the tenant user to impersonate). Returns a Sanctum token scoped to the tenant context. Logs entry to central `audit_events` with: `impersonator_id`, `impersonated_user_id`, `tenant_id`, `action = 'impersonation.start'`, `ip_address`, `user_agent`.
- [x] `POST /api/v1/platform/tenants/{tenant}/leave-impersonation` — end impersonation session. Logs entry to central `audit_events` with `action = 'impersonation.end'`.
- [x] `GET /api/v1/platform/impersonation-sessions` — list active impersonation sessions (from central `audit_events` where `action = 'impersonation.start'` and no matching `impersonation.end`). Rate-limited to `RateLimits::LIST`.
- [x] Impersonation token is a standard Sanctum token stored in the tenant DB's `personal_access_tokens` table, created for the impersonated user. The token has a `abilities` field containing `['impersonated-by:{platform_admin_public_id}']` so that tenant middleware can detect impersonation mode.
- [x] When a platform admin is in impersonation mode, tenant audit events include `impersonated_by` metadata pointing to the platform admin.
- [x] A platform admin cannot impersonate another platform admin — only tenant users.
- [x] A platform admin cannot impersonate their own account.

### Central Audit Events Table

- [x] `audit_events` table in the **central database** with columns: `id`, `public_id` (UUID v7), `user_id` (FK users, nullable — null for system actions), `action` (varchar), `entity_type` (varchar), `entity_id` (varchar — public_id), `payload` (JSONB), `ip_address` (varchar), `user_agent` (text), `created_at`.
- [x] Append-only: no UPDATE or DELETE operations allowed on this table. Enforce at application level (no model methods for update/delete).
- [x] Platform admin actions logged with `user_id` = the admin's central DB `id`, `action` = verb.noun format (e.g., `tenant.create`, `tenant.suspend`, `impersonation.start`).
- [x] `GET /api/v1/platform/audit-events` — list audit events with cursor pagination. Filters: `action`, `entity_type`, `user_id`, date range. Rate-limited to `RateLimits::LIST`.

### General

- [x] All endpoints follow `/api/v1/platform/` prefix — no tenant context resolution (no `X-Tenant` header).
- [x] All responses use API Resources with `public_id` only — never expose internal `id`.
- [x] Bilingual: `name_ar` required, `name_en` optional (falls back to `name_ar`).
- [x] Domain events emitted for all mutating actions.
- [x] All service methods use `DB::transaction()` for multi-write operations.
- [x] All service methods use try/catch with `Log::channel('platform')`.
- [x] All domain events implement `ShouldDispatchAfterCommit`.
- [x] All enums used in code — no magic numbers.
- [x] Cursor pagination on all list endpoints (tenants, admins, audit events).

---

## Non-Functional Requirements

### Pagination

- All list endpoints (`tenants`, `admins`, `audit-events`) use **cursor pagination** — expected > 1000 rows at scale.
- No full-list endpoints in this module.

### Caching

- Tenant list/show cached at `{tenant_slug}:platform:tenant:{public_id}` with TTL 300s (5 min) — invalidated on tenant update/suspend/reactivate.
- Platform admin capability list not needed (platform admins have full access to platform APIs, no ABAC for platform endpoints).
- Impersonation sessions are not cached — always queried from audit events.

### Rate Limiting

- Auth endpoints: `RateLimits::AUTH_LOGIN` (5/min per email+IP)
- Mutating endpoints: `RateLimits::MUTATE` (30/min per user)
- List endpoints: `RateLimits::LIST` (60/min per user)

### Database Transactions

- `TenantProvisioningService::provision()` — wraps central row + domain creation in `DB::transaction()`
- `suspend()` — updates `is_active` + audit event → `DB::transaction()`
- `reactivate()` — updates `is_active` + audit event → `DB::transaction()`
- `impersonate()` — creates token + audit events → `DB::transaction()`
- Single updates (tenant profile update, admin create) — single write, no transaction needed

### Error Handling & Logging

- Module logging channel: `platform` — configured in `config/logging.php`
- All service methods use try/catch with `Log::channel('platform')`
- Structured context: `action`, `entity_type`, `entity_id`, `performed_by`
- Domain exceptions: `TenantAlreadySuspendedException`, `TenantAlreadyActiveException`, `CannotImpersonateSelfException`, `CannotImpersonatePlatformAdminException`, `PlatformAdminCannotDeactivateSelfException`

### Enums

- `App\Enums\AccountType` already exists with `PlatformAdmin(4)`. Use it in all comparisons.
- No new enums needed for this spec — `action` and `entity_type` are string-based in audit events.

### Queue Jobs

- `RunTenantMigrations` job dispatched to queue with `ShouldQueue` — tenant migration can take time.
- Domain events use `ShouldDispatchAfterCommit`.

---

## Out of Scope

- **Tenant user authentication** — belongs to Spec 003 (IAM). Platform admins authenticate against the central DB only.
- **Cross-tenant data queries** — platform admins read/write central DB only (except via impersonation).
- **Tenant subscription management / billing** — V2.
- **Cross-tenant analytics dashboard** — V2.
- **Global announcements** — V2.
- **SSO for platform admins** — V2. MVP uses email + password against central DB.
- **Password reset for platform admins** — V2. MVP: another platform admin can set a new password.
- **Email verification for platform admins** — MVP creates admins with `email_verified_at` set automatically.
- **Detailed audit trail in tenant DB during impersonation** — Spec 015 will consume domain events. This spec emits `impersonation.start` and `impersonation.end` events only.
- **Tenant database backup/restore** — infrastructure concern, not API.

---

## Open Questions

- [x] Should tenant `slug` be immutable after creation? **Decision:** Yes — `slug` is used for database naming (`momentum_tenant_{slug}`) and cache key prefixing. Changing it would require a database rename, which is risky. Reject updates to `slug` in validation.
- [x] Should `run-migrations` be synchronous or asynchronous? **Decision:** Asynchronous via queue job. Migration can take time on large tenant schemas. Return 202 Accepted with a job status poll endpoint. MVP: return 202 without a poll endpoint (logging only).
- [x] Should impersonation tokens have an auto-expiry? **Decision:** Yes — set Sanctum token expiration to 1 hour for impersonation tokens (configurable). Platform admin's own token has no expiry.
- [x] Should platform admin users live in the central `users` table or a separate `platform_admins` table? **Decision:** Central `users` table with `account_type = 4`. Simplest approach, consistent with the existing `AccountType` enum, avoids a separate auth guard.
- [x] Should `email_verified_at` be set automatically for platform admins? **Decision:** Yes — platform admins are created by other platform admins, not via self-registration. Set `email_verified_at = now()` on creation.

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.