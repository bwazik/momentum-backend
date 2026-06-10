# Release Policy — Momentum Backend

> Read for deployment, migrations, CI, or production-impacting changes.

---

## Environments

| Environment | Purpose |
|-------------|---------|
| Local | Developer machines |
| Production | Live tenants on VPS |

No staging environment in MVP.

---

## Deployment Flow

```
PR → feat/{spec} branch
  → GitHub Actions: Pint, Pest
  → Merge to main
  → GitHub Actions: test + deploy to VPS
  → Run central migrations
  → Run tenant template update + scheduled tenant DB rollout (maintenance window)
```

Backend and frontend deploy independently from their repos after respective CI passes.

---

## Database Migrations

### Central DB
- Standard Laravel migrations in `database/central/`
- Run once per deploy

### Tenant DBs
- Migrations in `database/tenant/` maintained in **template database**
- New tenants: cloned from updated template
- Existing tenants: batch migration during maintenance window (MVP accepts brief downtime)
- **Never** `migrate:fresh` on production
- Backwards-compatible migrations: add column → deploy code → remove old column in later release

---

## API Versioning

- MVP uses `/api/v1/` only
- Breaking response shape changes require spec approval and frontend coordination
- Update `openapi/openapi.json` on every contract change
- Set spec `Contract status:` to `stable` only after review

---

## OpenAPI Artifact

- Scramble generates spec; committed to `openapi/openapi.json`
- CI verifies OpenAPI is regenerated when API routes change (add check when scaffold exists)
- Frontend consumes this file for TypeScript type generation

---

## Secrets & Config

- New production env vars must be set on VPS before deploy
- Never commit `.env`

---

## Rollback

- Code: redeploy previous release artifact
- Migrations: prefer reversible migrations; document manual rollback in spec `plan.md` if not reversible

---

## Requires Sign-Off

- Schema changes on high-volume tables
- Auth flow changes
- Tenant provisioning / connection logic changes
- Multi-tenant batch migration execution

---

→ **Next:** [glossary.md](glossary.md)
