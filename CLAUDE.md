# Claude Code Instructions — Momentum Backend (Gov TMS)

Laravel API workspace. Before writing any code, follow the reading rules below.

---

## Business Truth (Shared)

The ultimate source of truth lives outside this repository:

- `../_blueprints/` — business requirements, ERD, architecture, access model, feature inventory

Read relevant blueprint files when `docs/ai/` is insufficient or when resolving ambiguity. Do not duplicate blueprint content into code comments.

---

## Always Read First

@docs/ai/context.md

---

## Milestones

Read `@docs/ai/roadmap.md` immediately after `context.md`.

---

## Working on a Feature

If the task references a spec number or feature name:

- Read `specs/{number}-{name}/spec.md`
- Read `specs/{number}-{name}/plan.md` (if it exists)
- Honor `Depends on:` — read dependency `plan.md` files for stable contracts
- Check `Provides APIs:` and `Contract status:` before changing public endpoints

---

## API Contract

- REST only, versioned at `/api/v1/`
- All responses via Laravel API Resources — never expose internal `id`; use `public_id` (UUID v7)
- Scramble generates OpenAPI — committed snapshot at `openapi/openapi.json`
- When changing API shape, update Scramble output and set `Contract status` in the spec

---

## Read Conditionally

| When the task involves... | Read |
|--------------------------|------|
| Module structure, services, data flow | `@docs/ai/architecture.md` |
| Auth, ABAC, tenancy, PII, impersonation | `@docs/ai/security-policy.md` |
| Patterns, conventions, refactoring | `@docs/ai/coding-standards.md` |
| Tests | `@docs/ai/testing-policy.md` |
| Deploy, CI, migrations | `@docs/ai/release-policy.md` |
| Domain terms | `@docs/ai/glossary.md` |
| Multi-tenancy provisioning details | `../_blueprints/08_Multi_Tenancy_Strategy.md` |
| ERD / schema | `../_blueprints/06_MVP_ERD.dbml` |

---

## Paired Frontend Spec

Frontend uses identical spec IDs where a UI exists. After stabilizing APIs for a spec, ensure `openapi/openapi.json` is updated so the frontend repo can regenerate TypeScript types.

---

## Branch Rules

- Branch per spec: `feat/{number}-{name}` from `main`
- Never commit spec work directly to `main`
- PR → merge → update `docs/ai/roadmap.md` status
- Confirm correct branch before starting work

---

## Rules — Always Follow

- Do not scan the full codebase unless required
- Smallest safe change only
- Database-per-tenant: no `tenant_id` column in tenant DB tables
- No cross-module ORM joins — use service calls / events per module boundary rules
- Do not bypass ABAC, audit logs, or permission gates
- Do not implement DMS, G2G, ERP, digital signature, or procurement modules
- If a change breaks a locked contract — stop and ask first

---

## Response Format

1. Files changed (brief reason)
2. Risks or side effects
3. What to test manually
