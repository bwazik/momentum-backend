# Spec: Organization Structure

> **Number:** 002
> **Date:** 2026-06-08
> **Status:** `completed`
> **Milestone:** M2 — Organization & IAM
> **Depends on:** `001-platform-tenancy` (tenant DB provisioning, base models, tenant context resolution)
> **Provides APIs:** Department CRUD, Position CRUD, Authority Grade CRUD, Working Calendar & Public Holiday CRUD, Organization chart read endpoints
> **Contract status:** `stable`
> **Frontend spec:** `../frontend/specs/007-organization-structure`
> **Author:** Momentum init
> **Branch:** `feat/002-organization-structure`
> **Base branch:** `main`

---

## Problem

Every task in Gov TMS flows through an organizational hierarchy — from a Minister down to an Employee. Without a configurable organization structure, the platform cannot resolve stage assignees, route escalations, enforce authority-grade-based policies, or calculate SLA deadlines that respect working calendars and public holidays.

GCC organizations have deeply nested department hierarchies (Sectors → Directorates → Sections → Units) and positions that exist independently of the people filling them (Minister, Undersecretary, Director, etc.). Today this structure lives in spreadsheets and manual org charts that break whenever someone transfers or a department is restructured. The platform needs a first-class organizational model that blueprint assignment rules, escalation engines, and SLA timers can all rely on.

---

## Goal

Deliver the Organization module — departments (nested hierarchy), positions (job slots), authority grades (seniority levels), and working calendars with public holidays — as the structural foundation that IAM, Blueprint, Task, and Tracking modules depend on. All data lives in the tenant DB; all endpoints use `public_id`; all changes are auditable.

---

## User Stories

### Departments

- As a **tenant admin**, I want to create a top-level department (sector/ministry), so that I can start building the organizational hierarchy.
- As a **tenant admin**, I want to create sub-departments under a parent department, so that the tree reflects our real structure (Sector → Directorate → Section → Unit).
- As a **tenant admin**, I want to update a department's bilingual name, so that both Arabic and English labels stay current after a rename.
- As a **tenant admin**, I want to deactivate a department, so that it no longer appears in dropdowns for new assignments but its historical data (positions, tasks) remains intact.
- As a **tenant admin**, I want to reactivate a previously deactivated department, so that I can restore it after an organizational change.
- As a **tenant admin**, I want to list departments as a flat paginated list with filters, so that I can browse and manage large organizations.
- As a **tenant admin**, I want to view the full department tree, so that I can see the organizational hierarchy at a glance.
- As an **authorized user**, I want to view a single department with its parent and children, so that I can understand context before making assignments.

### Authority Grades

- As a **tenant admin**, I want to create authority grades (e.g., Minister=1, Undersecretary=2, Director=3…), so that the system understands seniority for escalation routing and blueprint assignment rules.
- As a **tenant admin**, I want to update an authority grade's name or description, so that labels reflect official terminology.
- As a **tenant admin**, I want to deactivate an authority grade, so that it cannot be assigned to new positions but existing references remain valid.
- As an **authorized user**, I want to list all active authority grades, so that I can select one when creating a position.

### Positions

- As a **tenant admin**, I want to create a position (job slot) in a department with a title, authority grade, and reporting line, so that the system can assign tasks to the role — not the person.
- As a **tenant admin**, I want to set whether a position is the department head, so that the system can resolve "Department Head" assignment rules in blueprints.
- As a **tenant admin**, I want to set a position's reporting line (which position it reports to), so that escalation chains traverse the org hierarchy correctly.
- As a **tenant admin**, I want to transfer a position to a different department, so that organizational restructuring is reflected without losing history.
- As a **tenant admin**, I want to deactivate a position, so that it cannot be assigned new tasks but past task history is preserved.
- As a **tenant admin**, I want to reactivate a previously deactivated position, so that it can resume receiving assignments.
- As an **authorized user**, I want to list positions filtered by department and/or authority grade, so that I can find the right position for a blueprint assignment rule.
- As an **authorized user**, I want to view a position's details including current occupant, so that I can see who holds the role.

### Working Calendars & Public Holidays

- As a **tenant admin**, I want to create a working calendar with working days and hours, so that SLA timers count only working time.
- As a **tenant admin**, I want to set one calendar as the default for the organization, so that all SLA calculations fall back to it when no override exists.
- As a **tenant admin**, I want to add public holidays to a calendar, so that SLA timers skip non-working days.
- As a **tenant admin**, I want to mark a holiday as recurring (same date every year), so that I don't have to recreate it annually.
- As a **tenant admin**, I want to update a calendar or holiday, so that changes to working hours or holiday dates are immediately reflected.
- As a **tenant admin**, I want to delete a holiday from a calendar, so that cancelled holidays no longer affect SLA calculations.
- As an **authorized user**, I want to check whether a given date is a working day in a calendar, so that downstream modules (SLA, Task) can calculate deadlines correctly.

---

## Acceptance Criteria

### Departments

- [x] `departments` table exists in tenant DB with columns: `id`, `public_id`, `parent_department_id` (nullable self-ref FK), `name_en`, `name_ar`, `is_active`, `created_at`, `updated_at`, `deleted_at`
- [x] Creating a department requires `name_ar` (required) and `name_en` (optional; defaults to `name_ar` if empty)
- [x] `parent_department_id` must reference an active department or be null (top-level)
- [x] Nested set or adjacency list supports tree queries up to 5 levels deep
- [x] Deactivating a department sets `is_active = false`; does not cascade to child departments unless explicitly requested
- [x] Deleting a department soft-deletes it; also deactivates all active positions belonging to that department
- [x] Department list endpoint supports pagination, filtering by `is_active`, and filtering by `parent_department_id`
- [x] Department tree endpoint returns the full hierarchy as a nested JSON structure
- [x] Circular parent references are prevented (a department cannot be its own ancestor)
- [x] Every mutating action emits an audit event (deferred full audit to Spec 015; for now, emit a simple domain event)

### Authority Grades

- [x] `authority_grades` table exists in tenant DB with columns: `id`, `public_id`, `rank` (SMALLINT), `name_ar`, `name_en`, `description` (nullable), `created_at`, `updated_at`
- [x] `name_ar` is required; `name_en` is optional (defaults to `name_ar` if empty)
- [x] `rank` must be unique and positive; lower rank = higher authority
- [x] Deactivating is not applicable — grades are permanent; a new grade supersedes an old one by creating a new entry.
- [x] Authority grade list is unpaginated (expected < 20 entries per tenant)
- [x] Cannot delete a grade that is referenced by active positions

### Positions

- [x] `positions` table exists in tenant DB with columns: `id`, `public_id`, `department_id`, `title_ar`, `title_en`, `reports_to_position_id` (nullable self-ref FK), `authority_grade_id`, `is_department_head`, `is_active`, `created_at`, `updated_at`, `deleted_at`
- [x] Creating a position requires `department_id`, `title_ar`, and `authority_grade_id`; `title_en` is optional (defaults to `title_ar`)
- [x] `is_department_head` defaults to `false`; if set to `true`, any existing department head for the same department is set to `false` (only one head per department)
- [x] `reports_to_position_id` can be null (top of hierarchy); must reference an active position; circular references prevented
- [x] Transferring a position to a different department updates `department_id`; clears `is_department_head` flag; active assignments bound to the position follow automatically
- [x] Deactivating a position sets `is_active = false`; deactivated positions cannot receive new blueprint assignments or task stage assignments
- [x] Deleting a position soft-deletes it; task history referencing the position remains intact via non-cascading FK
- [x] Position list endpoint supports pagination, filtering by `department_id`, `authority_grade_id`, and `is_active`
- [x] Position detail endpoint includes current occupant as `null` placeholder (populated by Spec 003 IAM module)
- [x] Circular reporting-line references are prevented

### Working Calendars & Public Holidays

- [x] `working_calendars` table in tenant DB with columns: `id`, `public_id`, `name_ar`, `name_en`, `working_days` (comma-separated day indices), `working_hours_start` (TIME), `working_hours_end` (TIME), `timezone`, `is_default`, `created_at`, `updated_at`
- [x] `public_holidays` table in tenant DB with columns: `id`, `public_id`, `working_calendar_id`, `name_ar`, `name_en`, `holiday_date` (DATE), `is_recurring`, `created_at`
- [x] Setting a calendar as default (`is_default = true`) unsets the previous default (exactly one default per tenant)
- [x] Only one holiday per calendar per date (unique constraint on `working_calendar_id` + `holiday_date`)
- [x] `working_days` stored as comma-separated indices: `0=Sunday, 1=Monday, …, 6=Saturday`
- [x] Calendar list is unpaginated (expected < 5 per tenant)
- [x] Holiday list supports filtering by calendar and year
- [x] Deleting a calendar fails if it is the default calendar
- [x] A service method `isWorkingDay(Calendar, Date): bool` and `nextWorkingDay(Calendar, Date): Date` are available for downstream SLA consumption

### General

- [x] All endpoints follow `/api/v1/organization/` prefix
- [x] All responses use API Resources with `public_id` only — never expose internal `id`
- [x] All mutating endpoints require `organization.manage` capability (placeholder: `RequireTenantAdmin` middleware until IAM is complete)
- [x] Feature tests cover: department tree creation, circular-reference prevention, position department transfer, head-of-department uniqueness, calendar default toggle, holiday uniqueness
- [x] Bilingual fields: `name_ar` / `title_ar` is always required; `name_en` / `title_en` is optional and falls back to the Arabic value if empty

---

## Out of Scope

- **User management and IAM** (Spec 003: users, capability grants, delegations)
- **Visual org chart** (V2 — Spec 008 in frontend)
- **Org chart export** (V2)
- **Financial delegation thresholds per position** (V2)
- **Blueprint assignment resolution** (Spec 004 consumes position/grade data but does not define it here)
- **SLA timer calculations** (Spec 007 consumes calendars; this spec only provides the `isWorkingDay` / `nextWorkingDay` service methods)
- **Full audit trail persistence** (Spec 015; this spec emits domain events only)
- **Organization entity registration** — tenant-level org name is stored in `central.tenants.name_en` / `name_ar` (provisioned in Spec 001); no separate org entity table in tenant DB

---

## Open Questions

- [x] Should authority grades support soft deactivation? **Resolved:** Keep grades permanent for MVP. No `is_active` column. Supersede by creating a new grade.
- [x] Should department deactivation cascade to child departments by default? **Resolved:** Explicit `cascade_to_children` flag — default `false`.
- [x] Should the working calendar `timezone` default to the tenant's `timezone`? **Resolved:** Each calendar has its own `timezone` column, defaulting to `Asia/Riyadh` on creation.
- [x] Should position transfers create a revision history row? **Resolved:** In-place `department_id` update with audit event. Full revision history via Spec 015.