# Coding Standards — Momentum Backend

> Read when writing new code, refactoring, or unsure about structure.

---

## General Principles

- Explicit over implicit; one class, one responsibility
- Smallest change that satisfies the spec
- Match patterns in the active module before inventing new ones
- Module boundary rules in `architecture.md` are non-negotiable

---

## Naming Conventions

| Thing | Convention | Example |
|-------|-----------|---------|
| Classes | PascalCase | `TenantProvisioner` |
| Methods | camelCase | `provisionTenant()` |
| Variables | camelCase | `$tenantDatabase` |
| DB columns | snake_case | `public_id`, `created_at` |
| Routes | kebab-case | `/api/v1/platform/tenants` |
| Capabilities | dot notation | `task.view.organization` |
| Enum storage | TINYINT + PHP enum class | `TaskStatus::Active` |

---

## Architecture Patterns

### Controllers
- Thin: validate → delegate to service → return API Resource
- Versioned under `routes/api/v1/`
- No business logic

### Services (per module)
- All business logic lives in `app/Modules/{Module}/Services/`
- Injected via constructor
- Emit domain events for Audit / Tracking / Notification consumers

### Models
- Relationships, casts, scopes, soft deletes only
- Tenant DB models: **no** `tenant_id` attribute
- Route model binding resolves by `public_id`

### API Resources
- **Required** for every response
- Expose `public_id`, never internal `id`
- Transform column names to frontend-friendly keys where helpful

### Migrations
- `database/central/` for central DB
- `database/tenant/` for tenant template (applied to each tenant DB)

---

## Module Communication

- **Allowed:** Service method calls, domain events, queue jobs
- **Forbidden:** Importing another module's Eloquent models for queries

---

## Error Handling

- Custom domain exceptions per module where appropriate
- Consistent JSON error shape via exception handler
- 403 for ABAC denial (not 404, to avoid leaking existence on sensitive resources where policy dictates)

---

## What To Avoid

- `tenant_id` on tenant DB tables
- Hardcoded role checks (`if ($user->role === 'admin')`)
- Raw model return from controllers
- `env()` outside config files
- Cross-module Eloquent relationships spanning module boundaries
- God services >300 lines — split by use case

---

## Code Style

- **Formatter:** Laravel Pint (PSR-12)
- Run before commit: `./vendor/bin/pint`

---

## Dependencies

- No new Composer packages without team discussion
- Prefer Laravel-first solutions for MVP
