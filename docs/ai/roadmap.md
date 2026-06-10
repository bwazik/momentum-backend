# Implementation Roadmap — Momentum Backend

> Source of truth for backend execution order and stable contracts.
> Aligned with spec IDs in `../frontend/docs/ai/roadmap.md` where UI exists.
> Business truth: `../_blueprints/`

---

## Current Focus

**Active Milestone:** M2 — Organization & IAM
**Active Spec:** — (all M2 complete, next: M3 or 001-platform-admin supplement)
**Branch:** `main`

Do not implement specs marked ⬜ Not Started unless explicitly instructed.

---

## Milestone Overview

| # | Name | Status | Depends On |
|---|------|--------|------------|
| M1 | Platform & Core Foundation | ✅ Done | — |
| M2 | Organization & IAM | ✅ Done | M1 |
| M3 | Blueprint Engine | ⬜ Not Started | M2 |
| M4 | Task Execution & Lifecycle | ⬜ Not Started | M3 |
| M5 | SLA, Escalation & Notifications | ⬜ Not Started | M4 |
| M6 | Analytics, Follow-up & Search | ⬜ Not Started | M5 |
| M7 | Documents, Audit, Onboarding & Help | ⬜ Not Started | M4 |

**Legend:** ✅ Done · 🔄 In Progress · ⬜ Not Started · 🚧 Blocked

---

## Backend Spec Catalog

| Spec | Milestone | Domain | Frontend pair | Status |
|------|-----------|--------|---------------|--------|
| `001-platform-tenancy` | M1 | Platform + Core tenant resolution | `009-system-administration` (partial) | ✅ Done |
| `002-organization-structure` | M2 | Organization | `007-organization-structure` | ✅ Done |
| `003-iam-abac` | M2 | IAM | `009-system-administration` | ✅ Done |
| `004-blueprint-engine` | M3 | Blueprint | `004-blueprint-builder` | ⬜ Not Started |
| `005-task-execution` | M4 | Task creation & launch | `002-task-board`, `003-task-details` | ⬜ Not Started |
| `006-stage-lifecycle` | M4 | Stage/sub-stage progression | `003-task-details`, `005-workflow-visualization` | ⬜ Not Started |
| `007-sla-escalation` | M5 | Tracking & SLA | `006-follow-up-center` | ⬜ Not Started |
| `008-notifications` | M5 | Notification | — (backend-only delivery) | ⬜ Not Started |
| `009-analytics-reporting` | M6 | Analytics | `001-executive-dashboard`, `008-analytics-reporting`, `011-department-manager-dashboard` | ⬜ Not Started |
| `010-follow-up-board` | M6 | Follow-up & tracking API | `006-follow-up-center` | ⬜ Not Started |
| `011-search-discovery` | M6 | Search | — | ⬜ Not Started |
| `012-documents-attachments` | M7 | Document | `003-task-details` | ⬜ Not Started |
| `013-comments-collaboration` | M4 | Comments | `003-task-details` | ⬜ Not Started |
| `014-external-references` | M4 | External refs | `002-task-board` | ⬜ Not Started |
| `015-audit-trail` | M7 | Audit | `009-system-administration` | ⬜ Not Started |
| `016-delegation-oof` | M2 | Delegation | — | ⬜ Not Started |
| `017-confidentiality-access` | M2 | Confidential tasks | — | ⬜ Not Started |
| `018-localization-calendar` | M2 | Hijri, working calendar | — | ⬜ Not Started |
| `019-onboarding-training` | M7 | Onboarding | — | ⬜ Not Started |
| `020-help-center` | M7 | Help Center | `010-help-center` | ⬜ Not Started |

---

## M1 — Platform & Core Foundation

**Status:** ✅ Done

**Specs:**
- `001-platform-tenancy` — Central tenant registry, DB provisioning, connection switching, platform admin, impersonation audit — `main` — ✅ Done

**M1 will establish:**
- `tenants` table and central DB connection config
- Tenant resolution middleware (Header → DB switch)
- Template database provisioning workflow
- Redis key prefixing convention (`{slug}:`)
- Base model traits (soft delete, `public_id` generation, timestamps)
- Platform admin authentication context (central)
- Impersonation session initiation + audit hook

**Constraints for later milestones:**
- All tenant business modules use tenant connection only — never central DB for business data
- Queue jobs include tenant slug/id for worker context

---

## M2 — Organization & IAM

**Status:** ✅ Done

**Specs:** `002` ✅, `003` ✅, `016`, `017`, `018`

**Established by 002:**
- `departments` table (nested hierarchy with adjacency list, soft delete, bilingual names)
- `authority_grades` table (permanent seniority levels, no soft delete)
- `positions` table (job slots with reporting lines, department head flag, soft delete)
- `working_calendars` + `public_holidays` tables (working day calculation service)
- `/api/v1/organization/` endpoints (Department, AuthorityGrade, Position, WorkingCalendar, PublicHoliday CRUD)
- Domain events emitted for all mutating actions (consumed by Audit module in Spec 015)
- `TenantModel` updated: UUID v7 for `public_id`, route model binding by `public_id`, SoftDeletes opt-in per model
- `RequireTenantAdmin` middleware replaced by `RequireCapability` (ABAC-based)

**Established by 003:**
- `users` table extended with IAM fields (`public_id`, `name_ar`/`name_en`, `mobile`, `employee_id`, `account_type`, `preferred_language`, `is_active`, `is_out_of_office`, `out_of_office_delegate_user_id`, SoftDeletes)
- `User` model refactored: extends `Authenticatable` + `HasPublicId` trait + `HasApiTokens` + `SoftDeletes`
- `HasPublicId` trait extracted from `TenantModel` to `App\Models\Traits\HasPublicId`
- Authentication: Sanctum token-based login/logout (`POST /v1/iam/auth/login`, `POST /v1/iam/auth/logout`)
- User CRUD (`/api/v1/iam/users`) with bilingual fields, account types, soft-delete deactivate/reactivate
- Position assignments (`user_position_assignments` table, single primary per user, `Position.currentOccupant()`)
- Capability catalog (`capabilities` table, 25 system-defined MVP capabilities via seeder)
- Position capability grants (`position_capability_grants`, scope_type, revocable but not deletable)
- User capability grants (`user_capability_grants`, mandatory reason, scope_type, revocable)
- ABAC Policy Engine (`IamPolicy` singleton, per-request cache, capability + scope resolution)
- Monitoring scope grants (`monitoring_scope_grants`, department + blueprint_category scope)
- Delegations (`delegations` table, scoped by category/stage_type, revocable, `IamPolicy::getActiveDelegate()`)
- Out-of-office toggle (`is_out_of_office` + `out_of_office_delegate_user_id` on users)
- `RequireCapability` middleware replacing `RequireTenantAdmin` on all Organization + IAM routes
- `IamPolicy` registered as singleton with per-request cache, cleared on terminate

**Remaining M2 specs:** `016` (delegation supplement), `017` (confidentiality), `018` (localization/calendar)

---

## M3 — Blueprint Engine

**Status:** ⬜ Not Started · **Blocked by:** M2

**Specs:** `004`

**Will establish:** blueprints, stages, sub-stages, SLA policies, transitions, blueprint lock on first task

---

## M4 — Task Execution

**Status:** ⬜ Not Started · **Blocked by:** M3

**Specs:** `005`, `006`, `013`, `014`

**Will establish:** TaskRunner, stage instances, assignment resolution, comments, external references

---

## Dependency Map

```
M1: Platform & Core
  └── TenantResolver ─────────────────────────────────┐
  └── Central tenants registry ───────────────┐       │
                                             ↓       ↓
M2: Organization & IAM ─────────────────────────────────────────┐
  └── ABAC PolicyEngine ────────────────────────┐               │
  └── Positions / Departments ──────────┐       │               │
                                       ↓       ↓               ↓
M3: Blueprint Engine ─────────────────────────────────────────┤
  └── Blueprint definitions + lock ────────────────┐           │
                                                    ↓           │
M4: Task Execution ─────────────────────────────────────────────┤
  └── Task + StageInstance services ────────────────┐          │
                                                     ↓          │
M5: SLA & Notifications ────────────────────────────────────────┤
                                                     ↓          │
M6: Analytics & Follow-up ──────────────────────────────────────┤
                                                     ↓          │
M7: Documents, Audit, Help, Onboarding ─────────────────────────
```

---

## Rules for the AI Agent

- Never implement ⬜ specs without explicit instruction
- Before changing M1 contracts after ✅, check downstream dependents
- Update this file when a spec reaches ✅ Done — extract contracts from `plan.md`
- Update `openapi/openapi.json` when `Contract status: stable`
- Backend leads frontend — mark API `stable` before frontend implements against it

---

→ **Next:** [architecture.md](architecture.md)
