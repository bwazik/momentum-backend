# Implementation Plan: 001 Platform Tenancy & Core Foundation

## Overview

This document outlines the technical design for establishing the foundational multi-tenancy architecture for Gov TMS based on the approved spec 001. We will leverage the popular `stancl/tenancy` package (v3) to handle database, cache, and session isolation.

## Open Questions Resolved

1. **Template DB naming convention:** We will use `momentum_tenant_{slug}` for tenant databases. This will be configured in `stancl/tenancy` via the database manager settings.
2. **Scramble CI check:** Use `php artisan scramble:export` locally. CI will validate by running the command and checking `git diff --exit-code openapi/openapi.json` to ensure the API contract snapshot is up-to-date.

---

## Architecture & Configuration

### Package Installation
- Install `stancl/tenancy` v3.
- Publish its configuration and migrations: `php artisan vendor:publish --provider="Stancl\Tenancy\TenancyServiceProvider"`.

### Configuration (`config/tenancy.php`)
- **Bootstrappers:** Enable `DatabaseTenancyBootstrapper`, `CacheTenancyBootstrapper`, `RedisTenancyBootstrapper`, and `QueueTenancyBootstrapper` to ensure full data isolation.
- **Database Naming:** Configure the database prefix/suffix to generate databases as `momentum_tenant_{slug}`.
- **Subdomain Routing:** Configure central domains in `tenancy.php` so the package knows when to trigger the subdomain resolution middleware.

---

## Core Tenancy Models & Middleware

### Migrations
- Modify the default `tenants` migration provided by `stancl/tenancy` to include our MVP ERD columns:
  - `id` (bigint), `public_id` (UUID v7), `name_en`, `name_ar`, `slug`, `domain`, `is_active`, `settings` (JSONB), timestamps.
  - *Note: `stancl/tenancy` natively supports a JSON `data` column, but we will define explicit columns as per our ERD.*

### Custom Tenant Model
- Create `app/Models/Tenant.php` extending `Stancl\Tenancy\Database\Models\Tenant` and implementing `Stancl\Tenancy\Contracts\TenantWithDatabase`.
- Set up custom columns mapping so the package's internal logic works seamlessly with our schema (using `getCustomColumns()`).

### Middleware
- Use `InitializeTenancyBySubdomain` (provided by the package) on tenant API routes.
- Add a custom middleware `CheckTenantStatus` immediately after it to abort with `403 Forbidden` if the resolved tenant is inactive or suspended.

### Base Models
- **`CentralModel`:** Base Eloquent model for all central database entities.
- **`TenantModel`:** Base Eloquent model for all tenant database entities. The `stancl/tenancy` package automatically switches the default database connection for these models when tenancy is initialized. Includes `SoftDeletes` and a `HasPublicId` trait for UUID v7 auto-generation.

---

## Services

### `TenantProvisioningService`
Handles new tenant onboarding utilizing `stancl/tenancy`'s event system:
1. Validates tenant data and slug uniqueness.
2. Creates the `Tenant` model instance.
3. `stancl/tenancy` will automatically fire events (`CreatingDatabase`, `MigratingDatabase`) to provision the `momentum_tenant_{slug}` database and run tenant migrations from `database/migrations/tenant`.
4. Handles any post-provisioning logic (like seeding initial authority grades or roles).

### `ImpersonationService`
Handles platform admin impersonation within a tenant:
- `stancl/tenancy` provides native impersonation features (e.g., user impersonation across tenants).
- We will wrap this in our service to:
  - Set session variables (e.g., `impersonator_id`, `tenant_slug`).
  - Ensure audit events capture the impersonator's identity alongside the impersonated user's actions.

---

## Verification Plan

### Automated Tests

1. **TenantResolutionTest:**
   - Verifies `InitializeTenancyBySubdomain` correctly switches context.
   - Verifies `CheckTenantStatus` middleware blocks suspended tenants.
2. **TenantIsolationTest:**
   - Provisions two tenants and ensures that queries executed on tenant A cannot access tenant B's data (using package features).
3. **TenantProvisioningTest:**
   - Verifies database creation and migration execution when a new Tenant model is created.

### API Contract (OpenAPI)

- Add platform endpoints to controllers and use Scramble to export them to `openapi/openapi.json`.
- Ensure platform endpoints accurately reflect the UUID `public_id` convention for API responses.
