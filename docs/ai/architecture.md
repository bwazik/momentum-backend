# Architecture — Momentum Backend

> Read when touching module structure, services, data flow, or deployment.

Blueprint references: `../_blueprints/03_Module_Boundary_Map.md`, `../_blueprints/09_Architecture_Diagrams.md`, `../_blueprints/10_API_Frontend_Architecture.md`

---

## High-Level Structure

```
backend/
├── app/
│   ├── Modules/
│   │   ├── Core/
│   │   ├── Platform/
│   │   ├── Organization/
│   │   ├── Iam/
│   │   ├── Blueprint/
│   │   ├── Task/
│   │   ├── Tracking/
│   │   ├── Notification/
│   │   ├── Analytics/
│   │   ├── Document/
│   │   ├── Audit/
│   │   ├── Search/
│   │   ├── Onboarding/
│   │   └── HelpCenter/
│   ├── Http/
│   │   ├── Middleware/     # Tenant resolution, auth
│   │   └── Resources/      # API Resources (public_id only)
│   └── Providers/
├── database/
│   ├── central/            # Central management migrations
│   └── tenant/             # Per-tenant migrations (template DB)
├── openapi/
│   └── openapi.json        # Scramble snapshot (committed)
└── routes/
    └── api/v1/
```

Module folder names may adjust at scaffold time; boundaries are fixed.

---

## Database Topology

```
┌─────────────────────────────────────┐
│     Central Management DB           │
│  tenants (registry, db_name, slug)  │
└─────────────────────────────────────┘
          │ resolves connection
          ▼
┌──────────────┐  ┌──────────────┐
│ Tenant DB A  │  │ Tenant DB B  │  ... (no tenant_id columns)
└──────────────┘  └──────────────┘
```

**Provisioning:** Duplicate template database for new tenant (not full migration chain from scratch).

---

## Request Flow

```
HTTPS (mof.tms.app/api/v1/...)
  → Nginx → PHP-FPM
  → Extract `X-Tenant` header
  → Query central.tenants by slug
  → Sanctum auth (Redis session, tenant-prefixed)
  → Switch default DB connection to tenant DB
  → Middleware + Controller (thin)
  → Module Service
  → Tenant DB
  → API Resource response (public_id, no internal id)
```

Background jobs carry `tenant_id` / slug in payload; worker switches connection before execution.

---

## Module Boundaries (Rules)

| Rule | Meaning |
|------|---------|
| No cross-module DB joins | Query own tables only; call services for cross-module data |
| Blueprint → Task snapshot | Task stores blueprint rules at creation; never writes back |
| Assignment at runtime | Task calls Organization + IAM to resolve assignees |
| Tracking monitors | SLA owns `sla_timer_instances`, `escalations`; observes Task events |
| Analytics read-only | Query views / read models only |
| Audit receives events | Append-only; other modules emit, never read audit at runtime |
| IAM consulted | All permission checks via ABAC policy engine |
| Platform external to tenants | Platform module uses central DB only (except impersonation session) |

---

## Key Data Flows

**Task stage entry:** Task → IAM (assignee resolution) → Organization (position/dept) → Tracking (start SLA timer) → Notification → Audit

**SLA breach:** Tracking → Notification + Escalation record → Audit

**Tenant provision:** Platform → create DB from template → central registry row → Audit (central)

---

## API Design

- Prefix: `/api/v1/`
- Auth: Sanctum SPA cookies (CSRF via `/sanctum/csrf-cookie`)
- Validation: Form Requests
- Errors: Standard JSON (422 validation, 403 ABAC deny, 404)
- Documentation: Scramble auto-generates OpenAPI from routes, Form Requests, Resources

---

## Infrastructure (MVP)

| Component | MVP |
|-----------|-----|
| Hosting | Single VPS |
| Web | Nginx + PHP-FPM |
| Queue | Redis + Supervisor worker |
| Scheduler | Cron → `schedule:run` |
| Storage | Shared bucket, `tenant-{slug}/` prefix |
| Environments | Local + Production |
| CI | GitHub Actions: lint, test, deploy on `main` merge |

---

## Known Risk Areas

- **Tenant connection switching** — bug causes cross-tenant data exposure
- **ABAC policy engine** — incorrect deny/allow on confidential tasks
- **Blueprint lock** — race if lock check omitted at task launch
- **SLA timer pause/resume** — task suspension must sync timer state
- **Impersonation** — must log in both central and tenant audit trails
- **Multi-DB migrations** — maintenance window across all tenant DBs

---

## External Services (MVP)

| Service | Purpose |
|---------|---------|
| SMTP | Email notifications |
| S3 / MinIO | Document attachments |
| Redis | Cache, sessions, queues |

No G2G, ERP, or digital identity integrations in MVP.
