# Glossary — Momentum Backend

> Domain terms for code, specs, and API naming. Blueprint: `../_blueprints/02_Feature_Inventory.md`

---

## Core Domain

| Term | Definition | In code |
|------|------------|---------|
| **Blueprint** | Reusable workflow template (stages, SLAs, assignments) | `Blueprint` model, `blueprints` table |
| **Task** | Single work instance launched from a Blueprint | `Task` model |
| **Stage** | Named phase in a task lifecycle | `TaskStageInstance` |
| **Sub-stage** | Internal step within a stage | `TaskSubStageInstance` |
| **SLA Policy** | Reusable timer definition (hours/days) | `SlaPolicy` |
| **Escalation** | SLA breach or manual elevation to manager | `Escalation` |
| **External reference** | Link to correspondence/contract number (not DMS) | `TaskExternalReference` |

---

## Organization & Access

| Term | Definition | In code |
|------|------------|---------|
| **Tenant** | Organization using the platform (isolated DB) | `tenants` (central only) |
| **Position** | Configurable job slot, not a user | `positions` |
| **Authority grade** | Seniority tier (rank 1 = highest) | `authority_grades` |
| **Capability** | Named ABAC permission | `capabilities.key` |
| **Monitoring scope** | Follow-up visibility grant | `monitoring_scope_grants` |
| **Delegation** | Temporary authority transfer | `delegations` |

---

## Account Types

| Value | Meaning |
|-------|---------|
| `internal_user` | Normal employee |
| `tenant_admin` | Tenant system administrator |
| `external_auditor` | Read-only via audit grants |
| `platform_admin` | Gov TMS operator (central) |

---

## Task Status

| Value | Meaning |
|-------|---------|
| `draft` | Created, not launched |
| `active` | In progress |
| `suspended` | Paused (SLA timers paused) |
| `completed` | All stages done |
| `cancelled` | Terminated with reason |

---

## Classification

| Value | Meaning |
|-------|---------|
| `public` | Normal visibility rules |
| `internal` | Blocks lateral uninvolved visibility |
| `confidential` | Named access only |

---

## Key Capabilities (MVP sample)

| Capability | Meaning |
|------------|---------|
| `task.view.organization` | Org-wide task visibility |
| `task.view.department_touched` | Tasks that touched user's department |
| `task.view.follow_up_scope` | Follow-up board (needs monitoring scope) |
| `task.override_assignment` | Reassign active stage assignees |
| `blueprint.manage` | Activate/deactivate/duplicate blueprints |
| `analytics.view.organization` | Executive dashboard |

Full catalog: `../_blueprints/04_Visibility_Access_Rules.md` Section 5.

---

## Naming Preferences

| Use | Do not use |
|-----|------------|
| Tenant | Client, Account (in code) |
| Position | Role (for business titles) |
| Capability | Permission (in DB table names) |
| Stage | Step, Phase (in API — prefer `stage`) |
| `public_id` | `id`, `uuid` (in external API) |

---

→ **Next:** [spec-creation-guide.md](spec-creation-guide.md)
