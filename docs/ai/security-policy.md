# Security Policy — Momentum Backend

> Read for auth, ABAC, tenancy, PII, impersonation, confidential tasks, audit.
> Blueprint: `../_blueprints/04_Visibility_Access_Rules.md`

⚠️ Violations require explicit approval before proceeding.

---

## Authentication

- **Mechanism:** Laravel Sanctum SPA (HttpOnly session cookies)
- **Cross-origin:** API and frontend live on separate domains. CSRF requires proper Sanctum configuration.
- **Flow:** `GET /sanctum/csrf-cookie` → `POST /api/v1/login` → cookie on subsequent requests
- **Session storage:** Redis with tenant key prefix (`{slug}_session_*`)
- **Identity isolation:** Same email may exist in different tenants as separate accounts
- **SSO:** Not MVP; architecture must allow per-tenant SSO later

---

## Authorization — Policy-Based ABAC

**Not RBAC.** No hardcoded Minister/Director/Follow-Up roles.

| Concept | Purpose |
|---------|---------|
| `account_type` | Technical: `internal_user`, `tenant_admin`, `external_auditor`, `platform_admin` |
| `position` | Tenant-configurable job slot |
| `authority_grade` | Seniority rank (lower = higher authority) |
| `capability` | Named permission (e.g. `task.view.organization`) |
| `scoped grant` | Capability limited by department tree, tenant, etc. |
| `monitoring_scope` | Follow-up visibility = capability + scope grant |
| `relationship` | Initiator, assignee, past assignee, confidential participant |

**Rule:** Every mutating endpoint calls IAM policy engine. Never trust frontend checks alone.

Key MVP capabilities: see `glossary.md` and blueprint Section 5.

---

## Tenant Isolation — Database-per-Tenant

- Each tenant has a **physically separate PostgreSQL database**
- Tenant DB tables have **NO `tenant_id` column**
- Tenant resolved from `X-Tenant` header → `central.tenants` → connection switch
- **Never** accept tenant identifier from request body for scoping
- Queue jobs **must** include tenant context; worker switches DB before run
- Redis/cache keys **must** be tenant-prefixed
- Object storage paths **must** use `tenant-{slug}/` prefix
- Cross-tenant queries are **forbidden** except Platform admin on central DB

---

## Confidential Tasks

Classification: `public`, `internal`, `confidential`

- Confidential: named participants + governance positions + relationship-based access only
- Organization-wide capability **does not** bypass confidentiality by default
- `task.confidential.view_override` requires mandatory reason + audit event
- Override disabled by default per tenant settings

---

## PII & Sensitive Data

- **PII:** name, email, mobile, employee_id
- **Logs:** Never log passwords, session tokens, or full PII payloads
- **API responses:** Only fields defined in API Resources
- **Errors:** No stack traces or internal IDs in production responses

---

## API Security

- All routes under `/api/v1/` authenticated unless explicitly public
- Rate limiting on auth endpoints
- `public_id` (UUID v7) in URLs — prevents enumeration
- Internal `id` (BIGINT) never in JSON responses

---

## Audit Logging

- **Append-only** `audit_events` — no update/delete by anyone
- Log: user_id, event_type, entity, IP, user_agent, payload JSON
- Confidential override and impersonation are high-sensitivity events
- External auditors: access only via `audit_grants` on completed/archived tasks

---

## Platform Administration

- Platform admins operate on **central DB** only
- **Impersonation:** traceable session; initiation logged centrally; actions logged in tenant audit as impersonator identity
- No backdoor passwords or master keys

---

## Secrets

- All secrets in `.env` — never committed
- Access via `config()` only in application code
- No secrets in `docs/ai/` or specs

---

## Requires Explicit Approval

- Changes to authentication or session handling
- Changes to tenant connection resolution
- New endpoints returning confidential task content
- Disabling or bypassing ABAC checks
- New platform admin capabilities
- Third-party integrations receiving user data

---

→ **Next:** [testing-policy.md](testing-policy.md)
