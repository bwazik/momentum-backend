# Project Context — Momentum Backend (Gov TMS)

> Read every session. Dense project truth for the Laravel API workspace.
> Business blueprints: `../_blueprints/` (ultimate source of truth).
> Do NOT put secrets here.

---

## Required Reading Chain

Every agent working on this codebase **must** read these files in order before writing any code:

1. **`docs/ai/context.md`** — You are here. Project identity and critical rules.
2. **`docs/ai/roadmap.md`** — Milestone status, active spec, completed contracts.
3. **`docs/ai/architecture.md`** — Module boundaries, data flows, request flow.
4. **`docs/ai/coding-standards.md`** — Pagination, caching, transactions, logging, enums, rate limiting. **Must read before ANY implementation.**
5. **`docs/ai/security-policy.md`** — Auth, ABAC, tenancy, PII, impersonation. Must read when touching auth, permissions, or tenant isolation.
6. **`docs/ai/testing-policy.md`** — Test structure, coverage rules, factory usage.
7. **`docs/ai/release-policy.md`** — Deployment, migrations, API versioning.
8. **`docs/ai/glossary.md`** — Domain terms and naming conventions.
9. **`docs/ai/spec-creation-guide.md`** — Prompt templates for creating new specs and plans.

**Rule:** If you are writing code, you MUST have read `coding-standards.md` and `security-policy.md`. No exceptions.

---

## What Is This Project?

**Gov TMS (Momentum)** is a multi-tenant SaaS platform for government and large organizations in the GCC. It replaces manual task follow-up (*متابعة*) with **stage-level accountability**: every task follows a **Blueprint** (workflow template), progresses through **Stages/Sub-stages**, and enforces **SLAs** with escalation.

This repository is the **Laravel REST API** only. The Next.js frontend lives in `../frontend/`.

---

## Project Type

- [x] Multi-tenant SaaS (database-per-tenant)
- [x] Backend API (serves Next.js SPA via static API domain using `X-Tenant` header)

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.5 |
| Framework | Laravel 13 |
| Database | PostgreSQL 18+ (central DB + one DB per tenant) |
| Cache / Queue / Session | Redis (tenant-prefixed keys) |
| Auth | Laravel Sanctum (cross-origin supported) |
| API docs | Scramble → OpenAPI (`openapi/openapi.json`) |
| Testing | Pest (feature tests mandatory) |
| Object storage | S3-compatible (tenant-prefixed paths) |
| CI/CD | GitHub Actions → VPS deploy on merge to `main` |

---

## Architecture Summary

- **Central Management DB:** tenant registry, connection routing (`tenants` table only)
- **Tenant DBs:** all business data; **no `tenant_id` columns** (physical isolation)
- **Header routing:** Frontend passes `X-Tenant` header → resolve tenant → switch DB connection
- **Modules:** 15 bounded contexts — see `architecture.md`
- **Cross-origin:** Next.js lives on separate domain (e.g., `mof.momentum.test`), API is static (`api.momentum.test`)

---

## Key Modules (MVP)

| Module | Responsibility |
|--------|----------------|
| **Core** | Tenant context resolution, events, base traits, locale/Hijri helpers |
| **Platform** | Tenant provisioning, suspension, platform admins, impersonation |
| **Organization** | Departments, positions, authority grades, working calendar |
| **IAM** | Users, ABAC policy engine, delegation, monitoring scopes |
| **Blueprint** | Lifecycle templates, stages, sub-stages, SLA policies, transitions |
| **Task** | Task instances, stage progression, assignment resolution, comments |
| **Tracking & SLA** | SLA timers, escalation engine (monitors, does not own task data) |
| **Notification** | In-app + email alerts |
| **Analytics** | Read-only dashboards and reports |
| **Document** | Attachment metadata (files in object storage) |
| **Audit** | Immutable append-only event log |
| **Search** | PostgreSQL FTS, task search, recent activity |
| **Onboarding** | Access-profile journeys, quizzes |
| **Help Center** | Article CMS |

---

## Three Core Concepts

1. **Blueprint** — Reusable workflow template (stages, SLAs, assignments). Locked after first task launches.
2. **Task** — Single work instance from a Blueprint; immutable rules per instance.
3. **Stage / Sub-stage** — Atomic accountability unit with assignees, SLA, and completion rules.

---

## Critical Rules

1. **Database-per-tenant** — Never add `tenant_id` to tenant DB tables. Resolve tenant from `X-Tenant` header → central registry → switch connection.
2. **Public IDs** — API routes and responses use `public_id` (UUID v7). Never expose internal `id`.
3. **ABAC only** — No hardcoded business roles (Minister, Director). Use capabilities + positions + scopes.
4. **Blueprint lock** — Once a task launches, blueprint stage definitions are immutable.
5. **Module boundaries** — No cross-module ORM joins. Cross-module via service calls or events.
6. **Assignment at runtime** — Stage assignees resolved when entered (position, dept head, manual, delegation).
7. **Tracking monitors** — SLA module owns timers/escalations only; never writes task tables.
8. **Analytics read-only** — Never writes domain tables.
9. **Audit is append-only** — All modules emit events; audit never queried at runtime by other modules.
10. **API Resources required** — Every endpoint returns transformed JSON via API Resources.
11. **Arabic required** — `*_ar` fields required; `*_en` optional (copy Arabic if empty).
12. **Dates** — Store Gregorian; Hijri computed at presentation layer.
13. **Out of scope** — No DMS/correspondence, G2G, digital signatures, ERP/SAP, procurement module.

---

## MVP Scope

~178 features from `_blueprints/02_Feature_Inventory.md` (MVP-tagged). V2/V3 deferred unless spec explicitly pulls forward.

Specs are **domain/module-level** (~20), not one spec per feature.

---

## Paired Frontend

Spec IDs match `../frontend/specs/` where UI exists. Backend establishes API contract first; frontend generates TypeScript types from `openapi/openapi.json`.

---

## Current Focus

**Milestone 1 — Platform & Core Foundation: ✅ Done** (including 001-platform-admin supplement)
**Milestone 2 — Organization & IAM: 🔄 In Progress**
- ✅ `002-organization-structure` — Departments, positions, authority grades, working calendar
- ✅ `003-iam-abac` — Users, ABAC policy engine, capabilities, delegation, OOO
- ⬜ `016-delegation-oof` — Delegation supplement
- ⬜ `017-confidentiality-access` — Confidential task access model
- ⬜ `018-localization-calendar` — Hijri date helpers, working calendar

**Milestone 3 — Blueprint Engine: ✅ Done**
- ✅ `004-blueprint-engine` — Blueprint, stages, sub-stages, transitions, SLA policies

**Milestone 4 — Task Execution & Lifecycle: ✅ Done**
- ✅ `005-task-execution` — Task creation, launch, assignment resolution, lifecycle
- ✅ `006-stage-lifecycle` — Stage/sub-stage progression, return, override, history
- ✅ `013-comments-collaboration` — Task-level comments, replies, comment attachments, search indexing, recent activity
- ✅ `014-external-references` — External entity catalog, task external reference linking, search integration

**Milestone 5 — SLA, Escalation & Notifications: ✅ Done**
- ✅ `007-sla-escalation` — SLA timer engine, warning/breach detection, escalation management
- ✅ `008-notifications` — Event-driven notification module, in-app + email delivery, read/mutate APIs

**Milestone 6 — Analytics, Follow-up & Search: ✅ Done**
- ✅ `009-analytics-reporting` — Read-only executive/department dashboards, bottleneck view, aging report, ABAC-aware queries
- ✅ `010-follow-up-board` — Follow-up board, overdue/at-risk lists, bottleneck indicator, follow-up action log
- ✅ `011-search-discovery` — PostgreSQL full-text task search, structured filters, recent activity

**Milestone 7 — Documents, Audit, Onboarding & Help: 🔄 In Progress**
- ✅ `012-documents-attachments` — Document module, attachment metadata, file storage, versioning, download/preview
- ✅ `015-audit-trail` — Append-only audit event log (interface-based, all 92+ tenant events captured, central audit aligned)
- ⬜ `019-onboarding-training` — Access-profile journeys
- ⬜ `020-help-center` — Article CMS

---

→ **Next:** [roadmap.md](roadmap.md)

## What To Avoid

- Global `tenant_id` scopes on tenant DB models
- Hardcoded RBAC roles in code or migrations
- Raw Eloquent models in API responses
- Business logic in controllers — use module services
- Modifying locked contracts from completed milestones without roadmap review
- Scanning `../frontend/` for backend implementation decisions
