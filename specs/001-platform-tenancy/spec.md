# Spec: Platform Tenancy & Core Foundation

> **Number:** 001
> **Date:** 2026-06-07
> **Status:** `completed`
> **Milestone:** M1 — Platform & Core Foundation
> **Depends on:** none
> **Provides APIs:** Platform tenant management, tenant context resolution (internal), health endpoints
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/009-system-administration` (partial — tenant branding later)
> **Author:** Momentum init
> **Branch:** `main` (Merged from `feat/001-platform-tenancy`)
> **Base branch:** `main`

---

## Problem

Gov TMS is multi-tenant SaaS with **database-per-tenant** isolation. Without a platform foundation, no tenant can be provisioned, no request can resolve the correct database, and no business module can be built safely.

---

## Goal

Establish central tenant registry, tenant database provisioning from template, per-request tenant connection switching, and platform administrator operations with audited impersonation — the immovable foundation all other specs depend on.

---

## User Stories

- As a **platform operator**, I want to provision a new tenant with an isolated database, so that a ministry can onboard without sharing data with other tenants.
- As a **platform operator**, I want to suspend a tenant, so that access is blocked while data is preserved.
- As a **platform operator**, I want to initiate a traceable impersonation session, so that I can support a tenant without sharing passwords.
- As the **system**, I want to resolve the tenant from the request `X-Tenant` header and switch the database connection, so that every API call operates on the correct isolated dataset.
- As a **developer**, I want queue workers to receive tenant context, so that background jobs never execute against the wrong database.

---

## Acceptance Criteria

- [x] Central `tenants` table stores slug, database_name, settings, is_active per `../_blueprints/06_MVP_ERD.dbml`
- [x] New tenant provisioning creates DB from template and registers central row
- [x] HTTP middleware resolves `X-Tenant` header → tenant → switches default connection to tenant DB
- [x] Inactive/suspended tenant requests receive appropriate error (not wrong-tenant data)
- [x] Redis cache/session keys use tenant slug prefix
- [x] Platform admin actions on central DB are logged *(Deferred to Spec 015-audit-trail)*
- [x] Impersonation initiation logged centrally; tenant actions during session logged with impersonator identity *(Deferred to Spec 015-audit-trail)*
- [x] Base model provides `public_id` (UUID v7), soft deletes, timestamps for tenant models
- [x] Feature tests prove two tenants cannot read each other's data
- [x] `openapi/openapi.json` includes platform endpoints when implemented *(Deferred to Platform API implementation)*

---

## Out of Scope

- Tenant business users, org structure, IAM (spec 002/003)
- Custom domains (MVP: `X-Tenant` Header resolution only)
- Subscription billing automation (V2)
- Zero-downtime rolling migrations across tenant DBs (MVP accepts maintenance window)
- Frontend admin UI (frontend spec 009 — later, after API stable)

---

## Open Questions

- [x] Exact Scramble export command and CI check — define at Laravel scaffold *(Deferred to CI/CD setup)*
- [x] Template DB naming convention (`tms_tenant_{slug}` vs UUID) — decide in plan.md *(Decided: `momentum_tenant_{slug}`)*
