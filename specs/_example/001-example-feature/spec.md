# Spec: [Feature Name]

> **Number:** [NNN]
> **Date:** [YYYY-MM-DD]
> **Status:** `draft`
> **Milestone:** M[N] — [Milestone Name]
> **Depends on:** `[NNN-dependency-spec]` (brief description of what it provides)
> **Provides APIs:** [List all endpoints this spec will expose]
> **Contract status:** `draft`
> **Frontend spec:** `../frontend/specs/[NNN-frontend-spec]` (or `—` if backend-only)
> **Author:** [Your name]
> **Branch:** `feat/[NNN]-[feature-name]`
> **Base branch:** `main`

---

## Problem

[Explain WHY this feature is needed. What pain point or gap exists today? What breaks or is missing without it? Be specific about the current state and the desired future state.]

---

## Goal

[One paragraph describing what this spec delivers. Focus on the WHAT, not the HOW. Reference which modules are built and what downstream modules can rely on after this spec is complete.]

---

## User Stories

### [Group Name]

- As a **[role]**, I want to **[action]**, so that **[outcome]**.
- As a **[role]**, I want to **[action]**, so that **[outcome]**.

---

## Acceptance Criteria

### [Entity/Table Name]

- [ ] `table_name` in tenant DB includes: `id`, `public_id` (UUID v7, unique), [other columns], `created_at`, `updated_at`
- [ ] Model extends `TenantModel` (or `Authenticatable` + `HasPublicId` for User), uses `public_id` for route model binding
- [ ] All API responses expose `public_id` only — never internal `id`

### [Feature Area]

- [ ] `POST /api/v1/module/entity` — creates an entity; requires `[capability.key]` capability
- [ ] `GET /api/v1/module/entity` — lists entities; uses cursor pagination (expected > 1000 rows)
- [ ] [Add acceptance criteria for each endpoint and behavior]

---

## Non-Functional Requirements

### Pagination

- Endpoints listing [entity type] use **cursor pagination** (expected > 1000 rows per tenant)
- Endpoints listing [small reference table] return **full list** (expected < 100 rows)
- Cursor pagination requires `orderBy('id')` and returns `{data, next_cursor, has_more}`

### Caching

- [Entity type] catalog is cached at `{tenant_slug}:module:entities:all` with TTL 300s
- Cache invalidated on any [entity] create/update/delete event
- [Per-user data] cached at `{tenant_slug}:module:entity:{public_id}` with TTL 60s
- Cache invalidated on [specific events]

### Rate Limiting

- Auth endpoints: `RateLimits::AUTH_LOGIN` (5/min per email+IP)
- Mutating endpoints: `RateLimits::MUTATE` (30/min per user)
- List endpoints: `RateLimits::LIST` (60/min per user)

### Database Transactions

- [Operation that creates entity + grants]: `DB::transaction()` required
- [Operation that updates multiple records]: `DB::transaction()` required
- [Single create/update]: no transaction needed (single write)

### Error Handling & Logging

- Module logging channel: `[module_name]`
- All service methods use try/catch with `Log::channel('[module_name]')`
- Structured context: `tenant_slug`, `action`, `entity_type`, `entity_id`, `performed_by`
- Domain exceptions registered in `bootstrap/app.php`

### Enums

- Create `[EnumName]` enum in `app/Enums/` (cross-cutting) or `app/Modules/{Module}/Enums/` (module-specific)
- Use enum classes in all form requests via `Rule::enum(ClassName::class)`
- Use enum cases in all service and controller logic — never raw integers

### Queue Jobs

- [Operation X] dispatched to queue via `ShouldQueue` job
- All domain events implement `ShouldDispatchAfterCommit`
- Jobs include `public int $tries = 3` and `public array $backoff = [30, 60, 120]`

---

## Out of Scope

- [Item that could be confused with this feature but is explicitly deferred]
- [Item that belongs to another spec]

---

## Open Questions

- [ ] [Question that needs answering before implementation]
- [ ] [Question with a recommended answer in parentheses]

---

→ **Next:** Read `docs/ai/coding-standards.md` before creating `plan.md`.