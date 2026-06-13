# Database Schema Documentation

## Configurable Task Lifecycle Management Platform

>**Version:** 1.0  
>
>**Target Audience:** Software Engineers, Database Architects, and Future Maintainers  
>
>**Database Engines:** PostgreSQL (v18+)  

---

## 1. Architectural Decisions

This section explains the core architectural choices governing the database design of the Gov TMS platform. These decisions are optimized for compliance, performance, security, and developer clarity in a public sector context.

### 1.1 Database-per-Tenant Architecture
*   **Decision:** The platform uses a physical Database-per-Tenant isolation model rather than a shared database with tenant filters.
*   **Why It Was Chosen:** Government organizations require strict data sovereignty, physical separation of sensitive operations, and custom encryption options. A shared database runs the risk of accidental cross-tenant data leakage due to developer query mistakes (e.g., forgetting a filter) and makes independent tenant backup, recovery, or migration extremely difficult.
*   **Implications:** A Central Management Database stores tenant registries and connection details, while each tenant gets its own dedicated database instance.

### 1.2 Removal of `tenant_id` from Tenant DB Tables
*   **Decision:** All tables in the tenant-specific databases omit the `tenant_id` column. The column exists only in the Central Management database (`tenants` table).
*   **Why It Was Chosen:** Because the Database-per-Tenant architecture guarantees physical isolation at the database level, appending a `tenant_id` column to every table inside a tenant database is redundant. Eliminating this column keeps foreign keys cleaner, simplifies indexing, reduces disk storage, and avoids developer confusion regarding tenant scopes.

### 1.3 Explicit Bilingual Columns instead of JSON/JSONB
*   **Decision:** Translatable text columns are stored as explicit fields (e.g., `name_en` and `name_ar`) instead of unified JSONB translation structures.
*   **Why It Was Chosen:** Text columns are easier to index, sort, and search natively. JSONB querying incurs overhead and makes database-level constraint enforcement (e.g., Arabic is required, English is optional) cumbersome. With explicit columns, database constraints are simple (`NOT NULL` on `name_ar` and nullable on `name_en`), and index creation is straightforward. If a third language is introduced in V2+, translations will migrate to a dedicated translations translation table rather than polluting columns.

### 1.4 Immutable Blueprints after Launch
*   **Decision:** A Blueprint (lifecycle template) is locked and becomes completely immutable (`is_locked = true`) as soon as the first task instance is launched from it.
*   **Why It Was Chosen:** Tasks in progress rely on a fixed sequence of stages, SLAs, and assignment rules. If a blueprint could be modified while tasks were running, the execution engine would crash or exhibit erratic behavior, and historical audit data would be invalidated. To make edits, users use a "duplicate-to-edit" workflow, copying the blueprint to a new record and locking the original.

### 1.5 Separation of Account Types from Business Positions
*   **Decision:** User logins have technical account types (`users.account_type`), which are decoupled from their organizational business positions (`positions` table).
*   **Why It Was Chosen:** A user’s business position changes frequently (e.g., acting head, promotions, transfers), whereas their technical platform clearance (e.g., `internal_user`, `tenant_admin`, `external_auditor`) is a separate architectural concern. Decoupling them prevents business modifications from breaking login mechanisms or administrative system privileges.

### 1.6 Capability-Based Access Control (CBAC) instead of Hardcoded Roles
*   **Decision:** Business logic checks named permissions called "Capabilities" (e.g., `task.view.organization`) granted to positions or users, instead of hardcoding organizational roles (e.g., `if user.position == 'Minister'`).
*   **Why It Was Chosen:** Hardcoded roles make the application rigid and restrict customization across government entities. By using an Attribute-Based and Capability-Based Access Control model, permissions can be reassigned dynamically in the database as departments restructure without changes to application code.

### 1.7 Logical Archive instead of Physical Archive
*   **Decision:** Archived tasks are retained in the primary `tasks` table with an `archived_at` timestamp instead of being moved to separate archive tables.
*   **Why It Was Chosen:** Moving records to archive tables breaks foreign keys, complicates search indexes, and duplicates schema maintenance work. Storing an `archived_at` timestamp is clean, keeps historical records immediately accessible for reporting/search, and allows Laravel global query scopes to hide archived records by default.

### 1.8 PostgreSQL Full Text Search (FTS) for MVP
*   **Decision:** Full-text search (FTS) is built natively using PostgreSQL `tsvector` columns and `GIN` indexes instead of external search clusters like Elasticsearch.
*   **Why It Was Chosen:** Introducing external search engines increases infrastructure overhead, adds sync delay, and complicates staging setups. PostgreSQL FTS provides immediate consistency, natively supports English and Arabic lexers, and easily scales to hundreds of thousands of records, which satisfies MVP scale requirements.

### 1.9 Enums Implemented as TINYINT Mapped to Laravel Classes
*   **Decision:** System enums are stored as `TINYINT` in the database and cast to Laravel Enum classes in application code.
*   **Why It Was Chosen:** Database-level ENUM types are difficult to alter or migrate (requiring custom DDL scripts). `TINYINT` is extremely portable, highly performant for indexing, and uses only 1 byte of storage. Mappings are maintained in Laravel code, preserving flexibility while maintaining database efficiency.

### 1.10 Public Identifier Strategy (UUID v7)
*   **Decision:** The platform uses a dual-identifier strategy. All internal foreign keys use `BIGINT` (`id` column), while all API-facing entities utilize a separate `UUID v7` (`public_id` column).
*   **Why It Was Chosen:** Exposing internal auto-incrementing integers in URLs (e.g. `/api/tasks/55`) allows for ID enumeration and leaks business metrics (how many tasks exist). While UUID v4 solves this, it causes massive database index fragmentation. UUID v7 is time-sortable, eliminating fragmentation while preserving obscurity. `BIGINT` is retained internally to ensure complex relational joins (e.g. `tasks` -> `task_stage_instances` -> `users`) remain maximally performant.
*   **Implications:** Laravel Route Model Binding must be configured to resolve routes using the `public_id` column instead of the default `id`. API resources must never output the `id` column.

---

## 2. Entity Inventory by Domain

Below is the inventory of the 46 tables that make up the Gov TMS database schema, grouped by their architectural domains.

```
[Central Management DB]
 └── tenants

[Tenant DB]
 ├── Organization Domain
 │    ├── departments
 │    ├── authority_grades
 │    ├── positions
 │    ├── working_calendars
 │    └── public_holidays
 ├── IAM Domain
 │    ├── users
 │    ├── user_position_assignments
 │    ├── capabilities
 │    ├── position_capability_grants
 │    ├── user_capability_grants
 │    ├── monitoring_scope_grants
 │    ├── delegations
 │    └── audit_grants
 ├── Blueprint Domain
 │    ├── blueprint_categories
 │    ├── blueprints
 │    ├── stage_types
 │    ├── sla_policies
 │    ├── blueprint_stages
 │    ├── blueprint_sub_stages
 │    └── blueprint_stage_transitions
 ├── Task & Execution Domain
 │    ├── task_priorities
 │    ├── tasks
 │    ├── task_stage_instances
 │    ├── task_sub_stage_instances
 │    ├── task_stage_assignments
 │    ├── task_external_references
 │    ├── task_confidential_participants
 │    └── task_follow_up_actions
 ├── Confidentiality Domain
 │    ├── confidential_governance_participants
 │    └── confidential_access_events
 ├── Escalation Domain
 │    └── escalations
 ├── SLA Runtime Domain
 │    └── sla_timer_instances
 ├── Comments Domain
 │    └── comments
 ├── Document & Attachment Domain
 │    └── documents
 ├── External Reference Domain
 │    └── external_entities
 ├── Notification Domain
 │    └── notifications
 ├── Audit Domain
 │    └── audit_events
 ├── Help Center Domain
 │    ├── help_article_categories
 │    └── help_articles
 └── Onboarding Domain
      ├── onboarding_journeys
      ├── onboarding_journey_sections
      ├── onboarding_journey_steps
      ├── onboarding_quiz_questions
      ├── onboarding_user_progress
      └── onboarding_quiz_attempts
```

---

## 3. Table Dependency Map

To guarantee referential integrity, databases must initialize tables in a specific sequence. Below is the creation order based on foreign key dependencies:

1.  **Level 0 (No Foreign Key Dependencies):**
    *   `tenants` (Central Management DB)
    *   `departments`
    *   `authority_grades`
    *   `working_calendars`
    *   `capabilities`
    *   `blueprint_categories`
    *   `stage_types`
    *   `sla_policies`
    *   `task_priorities`
    *   `users`
    *   `external_entities`
    *   `help_article_categories`
    *   `onboarding_journeys`
2.  **Level 1 (Directly depend on Level 0):**
    *   `positions` (references `departments`, `authority_grades`)
    *   `public_holidays` (references `working_calendars`)
    *   `user_position_assignments` (references `users`, `positions`)
    *   `user_capability_grants` (references `users`, `capabilities`, `departments`)
    *   `delegations` (references `users`, `blueprint_categories`, `stage_types`)
    *   `audit_grants` (references `users`, `departments`)
    *   `blueprints` (references `blueprint_categories`, `departments`, `users`)
    *   `audit_events` (references `users`)
    *   `help_articles` (references `help_article_categories`, `users`)
    *   `onboarding_journey_sections` (references `onboarding_journeys`)
3.  **Level 2 (Depend on Levels 0 and 1):**
    *   `position_capability_grants` (references `positions`, `capabilities`, `departments`, `users`)
    *   `monitoring_scope_grants` (references `users`, `departments`, `blueprint_categories`)
    *   `confidential_governance_participants` (references `positions`, `departments`, `blueprint_categories`, `users`)
    *   `blueprint_stages` (references `blueprints`, `stage_types`, `sla_policies`, `positions`, `departments`)
    *   `blueprint_stage_transitions` (references `blueprints`, `blueprint_stages`)
    *   `tasks` (references `blueprints`, `task_priorities`, `users`)
    *   `comments` (references `tasks`, `users`)
    *   `documents` (references `users`) *(Note: parent_document_id self-references)*
    *   `notifications` (references `users`)
    *   `onboarding_journey_steps` (references `onboarding_journey_sections`)
    *   `onboarding_quiz_questions` (references `onboarding_journey_sections`)
    *   `onboarding_user_progress` (references `users`, `onboarding_journeys`, `onboarding_journey_sections`, `onboarding_journey_steps`)
    *   `onboarding_quiz_attempts` (references `users`, `onboarding_journey_sections`)
4.  **Level 3 (Depend on Levels 0, 1, and 2):**
    *   `blueprint_sub_stages` (references `blueprint_stages`, `sla_policies`, `positions`, `departments`)
    *   `task_stage_instances` (references `tasks`, `blueprint_stages`, `departments`)
    *   `task_confidential_participants` (references `tasks`, `users`)
    *   `task_follow_up_actions` (references `tasks`, `users`)
    *   `confidential_access_events` (references `tasks`, `users`)
    *   `task_external_references` (references `tasks`, `external_entities`)
5.  **Level 4 (Depend on Levels 0, 1, 2, and 3):**
    *   `task_sub_stage_instances` (references `tasks`, `task_stage_instances`, `blueprint_sub_stages`, `departments`)
    *   `escalations` (references `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `users`, `positions`)
    *   `sla_timer_instances` (references `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `sla_policies`, `working_calendars`)
    *   `task_stage_assignments` (references `tasks`, `task_stage_instances`, `task_sub_stage_instances`, `users`, `positions`)

---

## 4. Table-by-Table Reference

This section provides a thorough reference for every table in the Gov TMS schema. For each table, we document its purpose, business context, rules, and detailed column descriptions.

### 4.1 central_management.tenants
*   **Purpose:** Central tenant registry.
*   **Why It Exists:** To routing requests to the correct physically isolated database and manage global platform configuration for each tenant organization.
*   **Supported Modules:** Multi-Tenancy Router, System Administration.
*   **Important Business Rules:**
    *   Must occupy a separate database context.
    *   `slug` is URL-safe and unique, used for path routing (e.g., `tms.gov.sa/tenant-slug/`).
    *   `database_name` points to the physical DB to connect to.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English name of the tenant organization. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic name of the tenant organization. Required. |
| **slug** | VARCHAR(255) | Yes | | Unique, URL-safe routing key. Must be lowercase, hyphenated. |
| **domain** | VARCHAR(255) | No | NULL | Custom domain mapping if the tenant runs on a dedicated domain. |
| **database_name** | VARCHAR(255) | Yes | | Database name representing the tenant's isolated data schema. |
| **logo_path** | VARCHAR(255) | No | NULL | Path to the tenant logo file in object storage. |
| **default_language** | TINYINT | Yes | 1 | Default language for UI: `1 = ar` (Arabic), `2 = en` (English). |
| **timezone** | VARCHAR(100) | Yes | 'Asia/Riyadh' | Base timezone for SLA calculations. Default is KSA timezone. |
| **is_active** | BOOLEAN | Yes | true | Flag to block or allow access to the tenant instance. |
| **settings** | JSONB | No | NULL | Stores tenant configuration settings: customized classifications, confidentiality overrides, etc. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.2 tenant_db.departments
*   **Purpose:** Stores organizational units.
*   **Why It Exists:** To reflect the organizational hierarchy (sectors, directorates, departments, sections, units) needed for scope-based visibility, assignments, and reporting.
*   **Supported Modules:** Organization Module, IAM, Tasks (owning department tracking).
*   **Important Business Rules:**
    *   A department can have a parent, enabling a tree structure.
    *   If deleted, soft delete `deleted_at` is set, and active positions belonging to the department are marked inactive.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **parent_department_id** | BIGINT UNSIGNED | No | NULL | Self-references `departments.id`. Null represents top-level sectors/ministry. |
| **name_en** | VARCHAR(255) | Yes | | English department name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic department name. Required. |
| **is_active** | BOOLEAN | Yes | true | Toggle for department operational status. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.3 tenant_db.authority_grades
*   **Purpose:** Configuration of seniority levels.
*   **Why It Exists:** Seniority rankings govern escalation rules and automate blueprint design assignments.
*   **Supported Modules:** Organization Module, Escalations, Blueprints.
*   **Important Business Rules:**
    *   `rank` uses a lower number for higher authority (e.g., `1 = Minister`, `2 = Deputy Minister`).

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **rank** | SMALLINT | Yes | | Unsigned rank hierarchy number. Lower = higher seniority. |
| **name_en** | VARCHAR(255) | Yes | | English grade name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic grade name. Required. |
| **description** | VARCHAR(500) | No | NULL | Explanatory text of the grade tier. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.4 tenant_db.positions
*   **Purpose:** Configurable corporate job slots.
*   **Why It Exists:** Positions exist independently of users (occupants). Security policies and workflows are assigned to positions, preventing re-assignment pain when individuals change jobs.
*   **Supported Modules:** Organization Module, IAM, Execution (assignees).
*   **Important Business Rules:**
    *   Each position belongs to a department and must have an authority grade.
    *   `reports_to_position_id` represents the reporting manager position. If `NULL`, this is the top of the organization.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **department_id** | BIGINT UNSIGNED | Yes | | Foreign key to `departments.id`. |
| **title_en** | VARCHAR(255) | Yes | | English position title. |
| **title_ar** | VARCHAR(255) | Yes | | Arabic position title. Required. |
| **reports_to_position_id** | BIGINT UNSIGNED | No | NULL | Self-references `positions.id`. Defines escalation hierarchies. |
| **authority_grade_id** | BIGINT UNSIGNED | Yes | | Foreign key to `authority_grades.id`. |
| **is_department_head** | BOOLEAN | Yes | false | Identifies if this position is the head of the mapped department. |
| **is_active** | BOOLEAN | Yes | true | Status toggle. If inactive, position cannot be assigned tasks. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.5 tenant_db.working_calendars
*   **Purpose:** Operational calendars.
*   **Why It Exists:** SLA timers count down during working hours and exclude weekends.
*   **Supported Modules:** SLA Engine, Tasks.
*   **Important Business Rules:**
    *   `working_days` stores days as a comma-separated list: `0 = Sunday`, `1 = Monday`, ..., `6 = Saturday`.
    *   SLA calculations ignore time elapsed outside `working_hours_start` and `working_hours_end`.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **name_en** | VARCHAR(255) | Yes | | English calendar name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic calendar name. Required. |
| **working_days** | VARCHAR(50) | Yes | | List of working days, e.g., `"0,1,2,3,4"` for Sunday-Thursday. |
| **working_hours_start** | TIME | Yes | | Start of working day, e.g., `08:00:00`. |
| **working_hours_end** | TIME | Yes | | End of working day, e.g., `16:00:00`. |
| **timezone** | VARCHAR(100) | Yes | 'Asia/Riyadh' | Timezone context for calculating deadlines. |
| **is_default** | BOOLEAN | Yes | false | If true, this calendar applies tenant-wide unless overridden. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.6 tenant_db.public_holidays
*   **Purpose:** Non-working day overrides.
*   **Why It Exists:** To exclude national holidays (e.g., National Day, Eid holidays) from SLA counters.
*   **Supported Modules:** SLA Engine.
*   **Important Business Rules:**
    *   `is_recurring` determines if the holiday applies on the same date every year (Gregorian).
    *   Tasks and stage SLAs automatically adjust deadlines forward based on holidays.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **working_calendar_id** | BIGINT UNSIGNED | Yes | | Foreign key to `working_calendars.id`. |
| **name_en** | VARCHAR(255) | Yes | | English holiday name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic holiday name. Required. |
| **holiday_date** | DATE | Yes | | Specific Gregorian calendar date of the holiday. |
| **is_recurring** | BOOLEAN | Yes | false | True if repeating on same day/month annually. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.7 tenant_db.users
*   **Purpose:** Identity records for platform login and actions.
*   **Why It Exists:** To authenticate individuals and associate logs and assignments with a real identity.
*   **Supported Modules:** Authentication, IAM, Audit.
*   **Important Business Rules:**
    *   `account_type` defines technical administration privileges.
    *   `preferred_language` dictates UI language: `1 = ar`, `2 = en`.
    *   Soft deleted users are kept to maintain references in historical tasks.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **account_type** | TINYINT | Yes | | System account classification:<br>`1 = internal_user` (standard corporate employee)<br>`2 = tenant_admin` (local tenant administrator)<br>`3 = external_auditor` (read-only audit auditor)<br>`4 = platform_admin` (super-admin monitoring DB) |
| **name_en** | VARCHAR(255) | Yes | | English user display name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic user display name. Required. |
| **email** | VARCHAR(255) | Yes | | Login email. Must be unique. |
| **mobile** | VARCHAR(50) | No | NULL | Mobile phone number, used for SMS integrations in V2. |
| **employee_id** | VARCHAR(100) | No | NULL | Unique HR employee number identifier. |
| **preferred_language** | TINYINT | Yes | 1 | Language setting: `1 = ar` (Arabic), `2 = en` (English). |
| **is_active** | BOOLEAN | Yes | true | Status toggle. If false, login is blocked. |
| **email_verified_at** | TIMESTAMP | No | NULL | Verification timestamp. |
| **password** | VARCHAR(255) | Yes | | Hashed password string. |
| **remember_token** | VARCHAR(100) | No | NULL | Authentication token for persistent sessions. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.8 tenant_db.user_position_assignments
*   **Purpose:** Map of user occupants to corporate positions over time.
*   **Why It Exists:** To resolve who currently occupies a position while retaining historic transfers.
*   **Supported Modules:** IAM, Execution.
*   **Important Business Rules:**
    *   Only assignments with `ended_at IS NULL` are currently active.
    *   `is_primary` dictates which position tasks are routed to by default. Standard MVP restricts users to one active primary assignment at a time.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **position_id** | BIGINT UNSIGNED | Yes | | Foreign key to `positions.id`. |
| **started_at** | TIMESTAMP | Yes | | Date the occupant took office. |
| **ended_at** | TIMESTAMP | No | NULL | Date occupant vacated position. Null = currently active. |
| **is_primary** | BOOLEAN | Yes | true | Identifies primary position assignment for the user. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.9 tenant_db.capabilities
*   **Purpose:** Central catalog of system-defined capabilities.
*   **Why It Exists:** Defines the specific actions governed by the security/ABAC policy engine.
*   **Supported Modules:** IAM (Permissions).
*   **Important Business Rules:**
    *   `key` is a machine string checking permission constraints, e.g., `task.create`.
    *   `is_system_defined` determines if a capability was shipped out-of-the-box or created locally.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **key** | VARCHAR(255) | Yes | | Unique machine key, e.g., `task.view.organization`. |
| **name_en** | VARCHAR(255) | Yes | | English capability name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic capability name. Required. |
| **description** | VARCHAR(500) | No | NULL | Explains what business actions this capability enables. |
| **is_system_defined** | BOOLEAN | Yes | true | Set to true for out-of-the-box system-managed capabilities. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.10 tenant_db.position_capability_grants
*   **Purpose:** Mappings of capabilities to positions.
*   **Why It Exists:** Core IAM table. Positions are granted capabilities with scope limits.
*   **Supported Modules:** IAM (RBAC/CBAC).
*   **Important Business Rules:**
    *   Capabilities are granted to positions rather than users to simplify staffing changes.
    *   `scope_type` defines what data subset a position can access.
    *   To modify a grant, the old row is marked with `revoked_at` and a new row is created, maintaining a complete history.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **position_id** | BIGINT UNSIGNED | Yes | | Foreign key to `positions.id`. |
| **capability_id** | BIGINT UNSIGNED | Yes | | Foreign key to `capabilities.id`. |
| **scope_type** | TINYINT | Yes | | Scope restriction:<br>`1 = tenant` (all data in tenant context)<br>`2 = own_department` (user’s current department)<br>`3 = specific_department` (configured via scope_department_id)<br>`4 = department_tree` (own department + sub-departments)<br>`5 = own_tasks` (only tasks where user is assignee/initiator)<br>`6 = audit_grant` (restricted auditor scope) |
| **scope_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Required for scope type 3 or 4. |
| **granted_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Creator of grant. |
| **granted_at** | TIMESTAMP | Yes | | Date the capability grant took effect. |
| **revoked_at** | TIMESTAMP | No | NULL | Date the capability was revoked. Null means active. |

---

### 4.11 tenant_db.user_capability_grants
*   **Purpose:** Exception-only capabilities assigned to individuals.
*   **Why It Exists:** To handle temporary work assignments or specific executive exceptions without modifying position roles.
*   **Supported Modules:** IAM.
*   **Important Business Rules:**
    *   `reason` must be filled out to justify the direct user-level capability assignment.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **capability_id** | BIGINT UNSIGNED | Yes | | Foreign key to `capabilities.id`. |
| **scope_type** | TINYINT | Yes | | Scope restriction. Identical values to `position_capability_grants.scope_type`. |
| **scope_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. |
| **granted_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **granted_at** | TIMESTAMP | Yes | | Date user grant took effect. |
| **revoked_at** | TIMESTAMP | No | NULL | Date user grant was revoked. |
| **reason** | VARCHAR(500) | Yes | | Mandated justification for bypass grant. |

---

### 4.12 tenant_db.monitoring_scope_grants
*   **Purpose:** Configures scopes for follow-up monitors.
*   **Why It Exists:** Defines the exact departments and blueprint categories a follow-up specialist can monitor on their dashboard.
*   **Supported Modules:** IAM, Follow-up Board.
*   **Important Business Rules:**
    *   Combines with `task.view.follow_up_scope` capability to restrict dashboard views.
    *   If `blueprint_category_id` is null, the user monitors all categories within their department scope.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **scope_type** | TINYINT | Yes | | Monitoring department scope constraint:<br>`1 = tenant` (monitor all tenant departments)<br>`2 = own_department` (only user's own department)<br>`3 = specific_department` (configured department)<br>`4 = department_tree` (configured department and nested departments) |
| **scope_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Required for scope type 3 or 4. |
| **blueprint_category_id** | BIGINT UNSIGNED | No | NULL | References `blueprint_categories.id`. Null means all categories. |
| **granted_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **granted_at** | TIMESTAMP | Yes | | Date the grant was established. |
| **revoked_at** | TIMESTAMP | No | NULL | Expiry/Revocation date. |

---

### 4.13 tenant_db.delegations
*   **Purpose:** Out-of-office delegation rules.
*   **Why It Exists:** Automates routing when a user goes on leave, forwarding tasks to a delegate.
*   **Supported Modules:** IAM, Task Execution Engine.
*   **Important Business Rules:**
    *   `starts_at` and `ends_at` define active duration.
    *   Tasks are routed to the delegate only while the delegation is active.
    *   Can be restricted by blueprint category, stage type, or both.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **delegator_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Person delegating tasks. |
| **delegate_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Person receiving tasks. |
| **starts_at** | TIMESTAMP | Yes | | Start date and time of delegation. |
| **ends_at** | TIMESTAMP | Yes | | End date and time of delegation. |
| **scope_type** | TINYINT | Yes | | Scope boundary:<br>`1 = all` (delegate all tasks)<br>`2 = blueprint_category` (delegate tasks in category)<br>`3 = stage_type` (delegate tasks of stage type)<br>`4 = blueprint_category_and_stage_type` (delegate tasks matching both) |
| **blueprint_category_id** | BIGINT UNSIGNED | No | NULL | References `blueprint_categories.id`. |
| **stage_type_id** | BIGINT UNSIGNED | No | NULL | References `stage_types.id`. |
| **is_active** | BOOLEAN | Yes | true | Active flag. Allows manual delegation disablement. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.14 tenant_db.audit_grants
*   **Purpose:** Explicit grants for external auditors.
*   **Why It Exists:** External auditors are restricted to viewing only completed and archived tasks within a limited timeframe and department scope.
*   **Supported Modules:** IAM, Task Access Rules.
*   **Important Business Rules:**
    *   Auditors can access tasks only within the `date_range_start` and `date_range_end` window.
    *   If `department_id` is null, auditor can view allowed tasks across all departments.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **external_auditor_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Must be an `external_auditor`. |
| **granted_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **date_range_start** | DATE | Yes | | Start date of allowed task completion window. |
| **date_range_end** | DATE | Yes | | End date of allowed task completion window. |
| **department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id` to restrict audit scope. |
| **granted_at** | TIMESTAMP | Yes | | Date the audit grant was created. |
| **revoked_at** | TIMESTAMP | No | NULL | Revocation date. |

---

### 4.15 tenant_db.blueprint_categories
*   **Purpose:** Design-time category folders.
*   **Why It Exists:** To group blueprints. Also used to define delegation and monitoring boundaries.
*   **Supported Modules:** Blueprints, IAM, Task Execution.
*   **Important Business Rules:**
    *   Every blueprint belongs to exactly one category.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English category name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic category name. Required. |
| **display_order** | SMALLINT | Yes | 0 | Visual sorting order. |
| **is_active** | BOOLEAN | Yes | true | Status toggle. If false, category is hidden when creating blueprints. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.16 tenant_db.blueprints
*   **Purpose:** Reusable lifecycle workflow templates.
*   **Why It Exists:** Defines task lifecycles (stages, steps, SLAs, roles).
*   **Supported Modules:** Blueprint Module, Task Launch.
*   **Important Business Rules:**
    *   `scope` dictates whether a blueprint is organization-wide (`1`) or restricted to a department (`2`).
    *   `is_locked` is set to `true` when a task launches. Modifying a locked blueprint is blocked.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **category_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_categories.id`. |
| **name_en** | VARCHAR(255) | Yes | | English blueprint name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic blueprint name. Required. |
| **description_en** | VARCHAR(500) | No | NULL | English description. |
| **description_ar** | VARCHAR(500) | No | NULL | Arabic description. |
| **scope** | TINYINT | Yes | | Blueprint availability:<br>`1 = organization` (visible tenant-wide)<br>`2 = department` (restricted to department_id) |
| **department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Required if scope is department-level. |
| **is_locked** | BOOLEAN | Yes | false | If true, blueprint layout is locked. |
| **is_active** | BOOLEAN | Yes | true | Status toggle. Inactive blueprints cannot launch new tasks. |
| **created_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.17 tenant_db.stage_types
*   **Purpose:** Classification for stages.
*   **Why It Exists:** Standardizes stage behavior across templates.
*   **Supported Modules:** Blueprint Module, IAM Delegations.
*   **Important Business Rules:**
    *   System defaults: Action, Review, Approval, Decision, Information Gathering.
    *   Tenants can add custom stage types.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English stage type name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic stage type name. Required. |
| **is_system_default** | BOOLEAN | Yes | false | Protected flag. System defaults cannot be deleted. |
| **is_active** | BOOLEAN | Yes | true | Status toggle. |
| **display_order** | SMALLINT | Yes | 0 | Visual sorting order. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.18 tenant_db.sla_policies
*   **Purpose:** SLA templates.
*   **Why It Exists:** Centralizes SLA rules (duration and alerts) for reuse across stages.
*   **Supported Modules:** SLA Engine, Blueprints.
*   **Important Business Rules:**
    *   `sla_unit` indicates whether the policy is measured in hours or days.
    *   `warning_threshold_percentage` triggers a warning state before a breach occurs.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English policy name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic policy name. Required. |
| **sla_value** | SMALLINT | Yes | | Duration value. |
| **sla_unit** | TINYINT | Yes | | Duration unit:<br>`1 = hours` (working calendar hours)<br>`2 = days` (working calendar days) |
| **warning_threshold_percentage** | SMALLINT | Yes | 75 | Percentage of time elapsed before raising a warning status. |
| **is_active** | BOOLEAN | Yes | true | Toggle. Inactive policies cannot be added to new stages. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.19 tenant_db.blueprint_stages
*   **Purpose:** Stage templates within a blueprint.
*   **Why It Exists:** Defines the sequential steps tasks execute when launched.
*   **Supported Modules:** Blueprint Module, Task Execution Engine.
*   **Important Business Rules:**
    *   `sequence_order` defines the execution path. For MVP, stages run sequentially.
    *   `assignment_type` dictates how the executor is determined at runtime.
    *   `completion_rule` defines when a stage is marked complete.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **blueprint_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprints.id`. |
| **stage_type_id** | BIGINT UNSIGNED | Yes | | Foreign key to `stage_types.id`. |
| **sla_policy_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `sla_policies.id`. Null means no SLA. |
| **name_en** | VARCHAR(255) | Yes | | English stage name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic stage name. Required. |
| **description_en** | VARCHAR(500) | No | NULL | English stage description. |
| **description_ar** | VARCHAR(500) | No | NULL | Arabic stage description. |
| **sequence_order** | SMALLINT | Yes | | Sorting index. Combination `(blueprint_id, sequence_order)` must be unique. |
| **assignment_type** | TINYINT | Yes | | How assignments are resolved:<br>`1 = specific_position` (assigned to position)<br>`2 = department_head` (assigned to department head)<br>`3 = manual_at_launch` (manually chosen at launch) |
| **assigned_position_id** | BIGINT UNSIGNED | No | NULL | References `positions.id`. Required if assignment type is `1`. |
| **assigned_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Required if assignment type is `2`. |
| **assignment_cardinality** | TINYINT | Yes | 1 | Executor count:<br>`1 = single` (one assignee allowed)<br>`2 = multiple` (multiple assignees allowed) |
| **completion_rule** | TINYINT | Yes | 1 | Completion logic:<br>`1 = any_assignee` (first completion advances)<br>`2 = all_assignees` (all assignees must complete)<br>`3 = lead_assignee` (lead assignee completion advances) |
| **escalation_position_id** | BIGINT UNSIGNED | No | NULL | References `positions.id`. Escalates to this position instead of reports_to. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.20 tenant_db.blueprint_sub_stages
*   **Purpose:** Checklist items within a stage.
*   **Why It Exists:** For tracking sub-actions that must occur within a parent stage.
*   **Supported Modules:** Blueprint Module, Task Execution Engine.
*   **Important Business Rules:**
    *   Sub-stages run in parallel inside their parent stage.
    *   If `is_required` is true, the parent stage cannot complete until all required sub-stages are completed.
    *   Sub-stages have independent SLA policies.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **blueprint_stage_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_stages.id`. |
| **sla_policy_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `sla_policies.id`. |
| **name_en** | VARCHAR(255) | Yes | | English sub-stage name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic sub-stage name. Required. |
| **description_en** | VARCHAR(500) | No | NULL | English description. |
| **description_ar** | VARCHAR(500) | No | NULL | Arabic description. |
| **sequence_order** | SMALLINT | Yes | | Sorting index. Unique within `blueprint_stage_id`. |
| **is_required** | BOOLEAN | Yes | true | True means parent stage cannot close until this is completed. |
| **assignment_type** | TINYINT | Yes | | Mapped resolution type (same as blueprint_stages). |
| **assigned_position_id** | BIGINT UNSIGNED | No | NULL | References `positions.id`. |
| **assigned_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. |
| **assignment_cardinality** | TINYINT | Yes | 1 | Expected assignees (same as blueprint_stages). |
| **completion_rule** | TINYINT | Yes | 1 | Completion logic (same as blueprint_stages). |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.21 tenant_db.blueprint_stage_transitions
*   **Purpose:** Transition rules between stages.
*   **Why It Exists:** Validates allowable execution paths.
*   **Supported Modules:** Blueprint Module, Task Execution Engine.
*   **Important Business Rules:**
    *   `transition_type` dictates forward (`1 = advance`) or backward (`2 = return`) movement.
    *   Return transitions (`2`) can target any earlier stage, but require a mandatory justification logic.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **blueprint_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprints.id`. |
| **from_stage_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_stages.id`. |
| **to_stage_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_stages.id`. |
| **transition_type** | TINYINT | Yes | | Direction:<br>`1 = advance` (forward progression)<br>`2 = return` (backward rework) |
| **return_reason_required** | BOOLEAN | Yes | false | If true, user must enter a justification reason to return. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.22 tenant_db.task_priorities
*   **Purpose:** Configurable priorities.
*   **Why It Exists:** Replaces hardcoded values with tenant-managed records to support custom configurations.
*   **Supported Modules:** Tasks, Dashboard.
*   **Important Business Rules:**
    *   `severity_rank` dictates urgency: `1 = Critical`, `2 = Urgent`, `3 = Routine`.
    *   Includes a `color_code` for UI visualization (e.g. Hex `#E74C3C`).

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic name. Required. |
| **severity_rank** | SMALLINT | Yes | | Urgency rank. Lower is more severe. |
| **color_code** | VARCHAR(7) | No | NULL | Hex color for UI display, e.g., `#E74C3C`. |
| **is_default** | BOOLEAN | Yes | false | Default priority for new tasks if not set. |
| **is_active** | BOOLEAN | Yes | true | Status toggle. |
| **display_order** | SMALLINT | Yes | 0 | Visual sorting order. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.23 tenant_db.tasks
*   **Purpose:** Tasks launched from blueprints.
*   **Why It Exists:** Stores runtime state, description, and execution progress.
*   **Supported Modules:** Task Execution Engine, Audit, Search.
*   **Important Business Rules:**
    *   Arabic fields (`title_ar`, `description_ar`) are required. If English counterparts are empty, the system automatically copies the Arabic values into them.
    *   `status` tracks task execution state.
    *   `archived_at` marks a task as archived.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **blueprint_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprints.id`. |
| **priority_id** | BIGINT UNSIGNED | Yes | | Foreign key to `task_priorities.id`. |
| **title_en** | VARCHAR(255) | No | NULL | English title. Copied from Arabic if left empty. |
| **title_ar** | VARCHAR(255) | Yes | | Arabic title. Required. |
| **description_en** | TEXT | No | NULL | English description. Copied from Arabic if left empty. |
| **description_ar** | TEXT | Yes | | Arabic description. Required. |
| **classification_level** | TINYINT | Yes | 1 | Classification:<br>`1 = public` (standard view)<br>`2 = internal` (limited view)<br>`3 = confidential` (restricted view) |
| **initiator_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Author/launcher. |
| **status** | TINYINT | Yes | 1 | Runtime status:<br>`1 = draft` (not launched)<br>`2 = active` (in progress)<br>`3 = suspended` (paused SLA timers)<br>`4 = completed` (finished)<br>`5 = cancelled` (aborted) |
| **due_date** | DATE | No | NULL | Overall target completion date. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **launched_at** | TIMESTAMP | No | NULL | Timestamp task moved to `active`. |
| **suspended_at** | TIMESTAMP | No | NULL | Timestamp of last suspension. |
| **resumed_at** | TIMESTAMP | No | NULL | Timestamp of last resume. |
| **completed_at** | TIMESTAMP | No | NULL | Timestamp task was completed. |
| **cancelled_at** | TIMESTAMP | No | NULL | Timestamp task was cancelled. |
| **cancellation_reason** | TEXT | No | NULL | Mandated reason if status is `cancelled`. |
| **archived_at** | TIMESTAMP | No | NULL | Logical archive timestamp. |
| **archived_by_user_id** | BIGINT UNSIGNED | No | NULL | References `users.id`. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.24 tenant_db.task_stage_instances
*   **Purpose:** Runtime stage instances.
*   **Why It Exists:** Holds the execution state for a stage. If a task is returned to a previous stage, a new stage instance is created.
*   **Supported Modules:** Task Execution Engine, SLA.
*   **Important Business Rules:**
    *   `owning_department_id` records the department of the active assignee on entry, serving as the department boundary for data access.
    *   `return_reason` is required if the stage ends with a status of `returned`.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **blueprint_stage_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_stages.id`. |
| **sequence_order** | SMALLINT | Yes | | Sequence order (copied from blueprint_stages). |
| **owning_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Set on entry. |
| **completion_rule** | TINYINT | Yes | | Copied from `blueprint_stages.completion_rule`. |
| **status** | TINYINT | Yes | 1 | Status:<br>`1 = pending` (waiting)<br>`2 = active` (in progress)<br>`3 = completed` (advanced)<br>`4 = returned` (returned to prior stage)<br>`5 = skipped` (skipped) |
| **entered_at** | TIMESTAMP | No | NULL | Timestamp stage became active. |
| **exited_at** | TIMESTAMP | No | NULL | Timestamp stage exited. |
| **completion_note** | TEXT | No | NULL | Summary note left by assignee on completion. |
| **return_reason** | TEXT | No | NULL | Mandated reason if status is `returned`. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.25 tenant_db.task_sub_stage_instances
*   **Purpose:** Runtime checklist tracking.
*   **Why It Exists:** Stores progress of sub-stages inside an active stage instance.
*   **Supported Modules:** Task Execution Engine, SLA.
*   **Important Business Rules:**
    *   Runs in parallel.
    *   If `is_required` is true, the parent `task_stage_instances` cannot close until this is completed.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **parent_stage_instance_id** | BIGINT UNSIGNED | Yes | | Foreign key to `task_stage_instances.id`. |
| **blueprint_sub_stage_id** | BIGINT UNSIGNED | Yes | | Foreign key to `blueprint_sub_stages.id`. |
| **sequence_order** | SMALLINT | Yes | | Sequence order (copied from blueprint_sub_stages). |
| **owning_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. |
| **is_required** | BOOLEAN | Yes | | Copied from `blueprint_sub_stages.is_required`. |
| **completion_rule** | TINYINT | Yes | | Copied from `blueprint_sub_stages.completion_rule`. |
| **status** | TINYINT | Yes | 1 | Status:<br>`1 = pending` (waiting)<br>`2 = active` (in progress)<br>`3 = completed` (done)<br>`4 = returned` (rework required) |
| **entered_at** | TIMESTAMP | No | NULL | Timestamp sub-stage became active. |
| **exited_at** | TIMESTAMP | No | NULL | Timestamp sub-stage exited. |
| **completion_note** | TEXT | No | NULL | Assignee completion note. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.26 tenant_db.task_stage_assignments
*   **Purpose:** Map of assignees to stage/sub-stage instances.
*   **Why It Exists:** Resolves who is assigned to perform the work. Supports multiple assignees.
*   **Supported Modules:** Task Execution Engine, Delegation.
*   **Important Business Rules:**
    *   `assignment_role` defines participation in completion calculations.
    *   `delegated_from_user_id` tracks if the work was routed via a delegation rule.
    *   If reassigned, the override reason and admin user are recorded.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_stage_instances.id`. |
| **sub_stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_sub_stage_instances.id`. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **position_id** | BIGINT UNSIGNED | No | NULL | References `positions.id`. Position at time of assignment. |
| **delegated_from_user_id** | BIGINT UNSIGNED | No | NULL | References `users.id` (original delegator user). |
| **assignment_role** | TINYINT | Yes | 1 | Assignment role:<br>`1 = required` (required assignee)<br>`2 = optional` (optional assignee)<br>`3 = lead` (primary lead assignee) |
| **is_completed** | BOOLEAN | Yes | false | True when the assignee submits their work. |
| **assigned_at** | TIMESTAMP | Yes | | Timestamp of assignment. |
| **completed_at** | TIMESTAMP | No | NULL | Timestamp assignee marked work complete. |
| **reassigned_at** | TIMESTAMP | No | NULL | Override reassignment timestamp. |
| **reassigned_by_user_id** | BIGINT UNSIGNED | No | NULL | References `users.id` (override admin). |
| **reassignment_reason** | TEXT | No | NULL | Mandated override justification. |

---

### 4.27 tenant_db.task_external_references
*   **Purpose:** External identifiers linked to tasks.
*   **Why It Exists:** Associates tasks with formal external records (contracts, ministerial decisions).
*   **Supported Modules:** External Reference Module, Search.
*   **Important Business Rules:**
    *   `external_entity_id` references the catalog of valid external entities.
    *   Search supports queries against the reference number.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **reference_type** | TINYINT | Yes | | Type of document reference:<br>`1 = correspondence` (official letter)<br>`2 = contract` (signed agreement)<br>`3 = ministerial_decision` (minister decree)<br>`4 = authority_decision` (agency decree)<br>`5 = meeting_minute` (committee minutes)<br>`6 = external_org_request` (request letter)<br>`7 = vendor_reference` (vendor proposal/invoice)<br>`8 = other` (other) |
| **reference_number** | VARCHAR(100) | Yes | | Indexable identifier string. |
| **external_entity_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `external_entities.id`. |
| **notes** | TEXT | No | NULL | Descriptive notes. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.28 tenant_db.task_confidential_participants
*   **Purpose:** Named participants for confidential tasks.
*   **Why It Exists:** Restricts access to confidential tasks to explicitly named users.
*   **Supported Modules:** IAM (Access Rules), Task Security.
*   **Important Business Rules:**
    *   If a task classification is confidential (`3`), access is restricted to the initiator, current assignees, and users in this table.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Participant granted access. |
| **added_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Authorizing user. |
| **added_at** | TIMESTAMP | Yes | | Date participant was granted access. |

---

### 4.29 tenant_db.task_follow_up_actions
*   **Purpose:** History log of manual follow-up actions.
*   **Why It Exists:** Allows follow-up specialists to record manual interventions (e.g., phone calls, emails) to resolve delayed tasks.
*   **Supported Modules:** Follow-up Board.
*   **Important Business Rules:**
    *   Append-only action log. Logs cannot be modified.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Logged by user. |
| **action_type** | TINYINT | Yes | | Follow-up action classification:<br>`1 = phone_call`<br>`2 = email`<br>`3 = meeting`<br>`4 = message`<br>`5 = other` |
| **notes** | TEXT | Yes | | Explanatory log notes. Required. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.30 tenant_db.confidential_governance_participants
*   **Purpose:** Automatic access grants for management.
*   **Why It Exists:** Prevents confidential tasks from being invisible to responsible leadership (e.g., Department Heads).
*   **Supported Modules:** IAM, Task Security.
*   **Important Business Rules:**
    *   Positions in this table inherit view access to confidential tasks within their department scope.
    *   If `blueprint_category_id` is null, access applies across all categories.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **position_id** | BIGINT UNSIGNED | Yes | | Foreign key to `positions.id`. |
| **scope_type** | TINYINT | Yes | | Access scope restriction:<br>`1 = tenant` (all confidential tasks)<br>`3 = specific_department` (only within department)<br>`4 = department_tree` (within department and sub-departments) |
| **scope_department_id** | BIGINT UNSIGNED | No | NULL | References `departments.id`. Required for scope type 3 or 4. |
| **blueprint_category_id** | BIGINT UNSIGNED | No | NULL | References `blueprint_categories.id`. |
| **applies_to_classification_level** | TINYINT | Yes | 3 | Classification scope constraint (always `3 = confidential` for MVP). |
| **created_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **revoked_at** | TIMESTAMP | No | NULL | Revocation date. |

---

### 4.31 tenant_db.confidential_access_events
*   **Purpose:** Dedicated audit trail for confidential access overrides.
*   **Why It Exists:** Logs administrative overrides of confidential tasks for security auditing.
*   **Supported Modules:** Audit, Security.
*   **Important Business Rules:**
    *   `reason` is mandatory when access is granted via a bypass override.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **access_type** | TINYINT | Yes | | Access categorization:<br>`1 = metadata_view` (viewed task header)<br>`2 = content_override` (admin override to view files)<br>`3 = participant_added` (added user to list)<br>`4 = participant_removed` (removed user from list) |
| **reason** | TEXT | No | NULL | Mandatory justification for overrides. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.32 tenant_db.escalations
*   **Purpose:** Escalation logs.
*   **Why It Exists:** Logs manual or SLA-triggered escalations to managers.
*   **Supported Modules:** SLA Engine, Escalations.
*   **Important Business Rules:**
    *   `escalated_to_user_id` resolves using the reporting structure `reports_to_position_id`.
    *   `escalation_type` defines whether the escalation was automated or manual.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_stage_instances.id`. |
| **sub_stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_sub_stage_instances.id`. |
| **escalation_type** | TINYINT | Yes | | Triggers:<br>`1 = auto_sla_breach` (system timer breach)<br>`2 = manual` (manually escalated by user) |
| **escalated_to_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id` (manager). |
| **escalated_to_position_id** | BIGINT UNSIGNED | No | NULL | References `positions.id`. Target manager position. |
| **escalated_by_user_id** | BIGINT UNSIGNED | No | NULL | References `users.id`. Null for automated breaches. |
| **reason** | TEXT | Yes | | Escalation details. Required. |
| **status** | TINYINT | Yes | 1 | Status:<br>`1 = open` (active alert)<br>`2 = resolved` (addressed) |
| **resolution_note** | TEXT | No | NULL | Manager actions taken. |
| **resolved_at** | TIMESTAMP | No | NULL | Timestamp of resolution. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.33 tenant_db.sla_timer_instances
*   **Purpose:** Active SLA timers.
*   **Why It Exists:** Tracks SLA deadlines for stages and sub-stages.
*   **Supported Modules:** SLA Engine.
*   **Important Business Rules:**
    *   `deadline_at` is calculated based on the SLA policy, working calendar, and public holidays.
    *   If a task is suspended, the timer status moves to `paused` (`5`) and the elapsed time is recorded.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_stage_instances.id`. |
| **sub_stage_instance_id** | BIGINT UNSIGNED | No | NULL | Foreign key to `task_sub_stage_instances.id`. |
| **sla_policy_id** | BIGINT UNSIGNED | Yes | | Foreign key to `sla_policies.id`. |
| **working_calendar_id** | BIGINT UNSIGNED | Yes | | Foreign key to `working_calendars.id`. |
| **started_at** | TIMESTAMP | Yes | | Timer start timestamp. |
| **deadline_at** | TIMESTAMP | Yes | | Calculated deadline timestamp. |
| **warning_at** | TIMESTAMP | Yes | | Calculated warning alert timestamp. |
| **paused_at** | TIMESTAMP | No | NULL | Timestamp when timer was paused. |
| **elapsed_before_pause** | INTEGER | Yes | 0 | Seconds elapsed before pause. Used to recalculate deadlines on resume. |
| **completed_at** | TIMESTAMP | No | NULL | Completion timestamp. Stops countdown. |
| **status** | TINYINT | Yes | 1 | Timer status:<br>`1 = running` (active countdown)<br>`2 = warning` (passed warning threshold)<br>`3 = breached` (passed deadline)<br>`4 = completed` (successfully stopped)<br>`5 = paused` (paused during task suspension) |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.34 tenant_db.comments
*   **Purpose:** Task comments.
*   **Why It Exists:** Enables collaboration directly within a task's context.
*   **Supported Modules:** Comments Module.
*   **Important Business Rules:**
    *   Supports single-level nesting (replies) using `parent_comment_id`. Nested replies cannot have replies of their own.
    *   Comments are visible to all users who have access to view the task.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **task_id** | BIGINT UNSIGNED | Yes | | Foreign key to `tasks.id`. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. Author. |
| **parent_comment_id** | BIGINT UNSIGNED | No | NULL | References `comments.id`. Null for top-level comments. |
| **body** | TEXT | Yes | | Comment text content. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.35 tenant_db.documents
*   **Purpose:** Metadata for file attachments.
*   **Why It Exists:** Stores access metadata and file paths. Files are uploaded to an object storage service.
*   **Supported Modules:** Document Module, Help Center.
*   **Important Business Rules:**
    *   Polymorphic fields `entity_type` and `entity_id` link files to tasks, comments, or help articles.
    *   `parent_document_id` supports document versioning.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **uploader_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **original_filename** | VARCHAR(255) | Yes | | Original uploaded file name. |
| **storage_path** | VARCHAR(255) | Yes | | File key path in storage (e.g. S3 key). |
| **mime_type** | VARCHAR(100) | Yes | | Browser MIME classification. |
| **size_bytes** | BIGINT | Yes | | File size in bytes. |
| **entity_type** | VARCHAR(100) | Yes | | Polymorphic type context: `task`, `comment`, `help_article`, etc. |
| **entity_id** | BIGINT UNSIGNED | Yes | | Polymorphic entity ID. |
| **version_number** | SMALLINT | Yes | 1 | Document version counter. |
| **parent_document_id** | BIGINT UNSIGNED | No | NULL | Self-references `documents.id`. Links to previous version. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.36 tenant_db.external_entities
*   **Purpose:** Directory of external organizations.
*   **Why It Exists:** Normalizes external organizations that issue reference letters or contract numbers, ensuring search consistency.
*   **Supported Modules:** External Reference Module.
*   **Important Business Rules:**
    *   `entity_type` categorizes the external organization.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English entity name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic entity name. Required. |
| **entity_type** | TINYINT | Yes | | Entity type classification:<br>`1 = government_ministry`<br>`2 = government_authority`<br>`3 = semi_government`<br>`4 = university`<br>`5 = hospital`<br>`6 = private_company`<br>`7 = vendor`<br>`8 = other` |
| **is_active** | BOOLEAN | Yes | true | Status toggle. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.37 tenant_db.notifications
*   **Purpose:** Notification queue.
*   **Why It Exists:** Stores in-app alerts and notifications. Follows Laravel notification conventions.
*   **Supported Modules:** Notifications Module.
*   **Important Business Rules:**
    *   Polymorphic fields `notifiable_type` and `notifiable_id` associate alerts with their source (tasks, stages, or escalations).
    *   `read_at` is updated when the user reads the notification in the UI.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. *(Typically UUID string representation in Laravel)* |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id` (recipient). |
| **type** | VARCHAR(255) | Yes | | Class mapping name, e.g., `App\Notifications\SlaBreach`. |
| **notifiable_type** | VARCHAR(100) | Yes | | Source type, e.g., `task`, `escalation`. |
| **notifiable_id** | BIGINT UNSIGNED | Yes | | Source entity ID. |
| **data** | JSONB | Yes | | Notification payload: title, description, deep link, etc. |
| **read_at** | TIMESTAMP | No | NULL | Read timestamp. Null means unread. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |

---

### 4.38 tenant_db.audit_events
*   **Purpose:** Append-only transaction audit log.
*   **Why It Exists:** Provides an audit log for security compliance. Modifying or deleting records is prevented.
*   **Supported Modules:** Audit Module, Security.
*   **Important Business Rules:**
    *   Captures user information, client IP address, and user agent.
    *   `payload` stores a JSON snapshot of the change (e.g., old and new values).

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **event_type** | VARCHAR(100) | Yes | | Action tag, e.g., `task.created`, `assignment.overridden`. |
| **entity_type** | VARCHAR(100) | Yes | | Polymorphic entity category, e.g., `task`, `user`, `blueprint`. |
| **entity_id** | BIGINT UNSIGNED | Yes | | Polymorphic entity ID. |
| **user_id** | BIGINT UNSIGNED | No | NULL | References `users.id`. Null for system automated actions. |
| **ip_address** | VARCHAR(45) | No | NULL | Client IP (IPv4 or IPv6 format). |
| **user_agent** | VARCHAR(500) | No | NULL | User Agent string. Required for government auditing compliance. |
| **payload** | JSONB | No | NULL | Stores change details (before/after snapshots). |
| **created_at** | TIMESTAMP | Yes | | Log timestamp. |

---

### 4.39 tenant_db.help_article_categories
*   **Purpose:** Categories for help articles.
*   **Why It Exists:** Groups help articles under categories.
*   **Supported Modules:** Help Center.
*   **Important Business Rules:**
    *   Help articles belong to a flat category structure.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **name_en** | VARCHAR(255) | Yes | | English category name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic category name. Required. |
| **display_order** | SMALLINT | Yes | 0 | Visual sorting order. |
| **is_active** | BOOLEAN | Yes | true | Toggle category availability. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.40 tenant_db.help_articles
*   **Purpose:** Help articles database.
*   **Why It Exists:** Stores user guides and self-service help content.
*   **Supported Modules:** Help Center, Search.
*   **Important Business Rules:**
    *   If English content is empty, Arabic is copied over.
    *   Draft articles (`is_published = false`) are visible only to users with the `helpcenter.manage` capability.
    *   Full-text search is supported via Arabic and English `tsvector` columns.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **category_id** | BIGINT UNSIGNED | Yes | | Foreign key to `help_article_categories.id`. |
| **title_en** | VARCHAR(255) | No | NULL | English article title. Copied from Arabic if empty. |
| **title_ar** | VARCHAR(255) | Yes | | Arabic article title. Required. |
| **body_en** | TEXT | No | NULL | Rich text English body. |
| **body_ar** | TEXT | Yes | | Rich text Arabic body. Required. |
| **is_published** | BOOLEAN | Yes | false | True means visible to all users. False means draft. |
| **display_order** | SMALLINT | Yes | 0 | Sorting position within the category. |
| **view_count** | INTEGER | Yes | 0 | View counter for basic usage analytics. |
| **search_vector_ar** | TSVECTOR | No | NULL | PostgreSQL search vector index (Arabic). |
| **search_vector_en** | TSVECTOR | No | NULL | PostgreSQL search vector index (English). |
| **created_by_user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **updated_by_user_id** | BIGINT UNSIGNED | No | NULL | References `users.id`. |
| **published_at** | TIMESTAMP | No | NULL | Timestamp article was published. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |
| **deleted_at** | TIMESTAMP | No | NULL | Soft delete timestamp. |

---

### 4.41 tenant_db.onboarding_journeys
*   **Purpose:** Custom onboarding guides.
*   **Why It Exists:** Provides guided onboarding walkthroughs based on user access levels.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   `detection_rule` uses JSON pattern matching to determine which journey a user receives based on their capabilities.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **journey_key** | VARCHAR(100) | Yes | | Unique key (e.g. `executive`, `admin`). |
| **name_en** | VARCHAR(255) | Yes | | English journey name. |
| **name_ar** | VARCHAR(255) | Yes | | Arabic journey name. Required. |
| **description_en** | TEXT | No | NULL | English description. |
| **description_ar** | TEXT | No | NULL | Arabic description. |
| **detection_rule** | JSONB | Yes | | Rule map matching capabilities to journeys. |
| **display_order** | SMALLINT | Yes | 0 | Visual ordering sorting value. |
| **is_active** | BOOLEAN | Yes | true | Toggle journey availability. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.42 tenant_db.onboarding_journey_sections
*   **Purpose:** Chapters/Sections inside an onboarding journey.
*   **Why It Exists:** Divides the onboarding process into manageable segments. Each segment ends with a knowledge check quiz.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   The combination `(journey_id, sequence_order)` must be unique.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **journey_id** | BIGINT UNSIGNED | Yes | | Foreign key to `onboarding_journeys.id`. |
| **title_en** | VARCHAR(255) | Yes | | English section title. |
| **title_ar** | VARCHAR(255) | Yes | | Arabic section title. Required. |
| **sequence_order** | SMALLINT | Yes | | Sorting order within the journey. |
| **pass_threshold** | SMALLINT | Yes | 70 | Minimum score percentage (0-100) required to pass the section quiz. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.43 tenant_db.onboarding_journey_steps
*   **Purpose:** Walkthrough steps within an onboarding section.
*   **Why It Exists:** Stores the UI highlights and step instructions.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   `target_selector` contains the CSS selector (e.g. `#create-task-btn`) to target and highlight elements during the guided tour.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **public_id** | UUID | Yes | | UUID v7 public identifier for API routing and obfuscation. |
| **section_id** | BIGINT UNSIGNED | Yes | | Foreign key to `onboarding_journey_sections.id`. |
| **title_en** | VARCHAR(255) | Yes | | English step title. |
| **title_ar** | VARCHAR(255) | Yes | | Arabic step title. Required. |
| **content_en** | TEXT | No | NULL | Detailed step content (English). |
| **content_ar** | TEXT | No | NULL | Detailed step content (Arabic). |
| **target_selector** | VARCHAR(255) | No | NULL | CSS selector targeting UI elements for highlights. |
| **sequence_order** | SMALLINT | Yes | | Sorting index within the section. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.44 tenant_db.onboarding_quiz_questions
*   **Purpose:** Quiz questions.
*   **Why It Exists:** Knowledge checks verify that the user understands the onboarding content.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   `options` is a JSONB array containing multiple-choice options (English/Arabic text and correct answer flags).

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **section_id** | BIGINT UNSIGNED | Yes | | Foreign key to `onboarding_journey_sections.id`. |
| **question_en** | TEXT | Yes | | English question text. |
| **question_ar** | TEXT | Yes | | Arabic question text. Required. |
| **options** | JSONB | Yes | | Array of answers: `[{"text_ar": "..", "text_en": "..", "is_correct": true}]`. |
| **display_order** | SMALLINT | Yes | 0 | Visual sorting order. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.45 tenant_db.onboarding_user_progress
*   **Purpose:** Track onboarding progress for each user.
*   **Why It Exists:** Allows users to resume onboarding where they left off.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   Tracks the user's current section and step in their active onboarding journey.
    *   Admins monitor progress to identify drop-off rates and completion status.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **journey_id** | BIGINT UNSIGNED | Yes | | Foreign key to `onboarding_journeys.id`. |
| **status** | TINYINT | Yes | 1 | User progress status:<br>`1 = not_started` (not begun)<br>`2 = in_progress` (started walk)<br>`3 = completed` (passed all section quizzes)<br>`4 = skipped` (skipped onboarding) |
| **current_section_id** | BIGINT UNSIGNED | No | NULL | References `onboarding_journey_sections.id`. Current chapter. |
| **current_step_id** | BIGINT UNSIGNED | No | NULL | References `onboarding_journey_steps.id`. Current step. |
| **skipped_at** | TIMESTAMP | No | NULL | Timestamp if the user skipped the onboarding tour. |
| **completed_at** | TIMESTAMP | No | NULL | Completion timestamp. |
| **created_at** | TIMESTAMP | Yes | | Record creation timestamp. |
| **updated_at** | TIMESTAMP | Yes | | Record last update timestamp. |

---

### 4.46 tenant_db.onboarding_quiz_attempts
*   **Purpose:** Results of onboarding quizzes.
*   **Why It Exists:** Logs quiz performance. Used to determine if the user can proceed to the next section.
*   **Supported Modules:** User Onboarding.
*   **Important Business Rules:**
    *   `score` stores the score percentage (0-100).
    *   `answers` logs the user's responses for auditing and review.

| Column | Data Type | Required? | Default | Purpose & Business Rules |
| :--- | :--- | :--- | :--- | :--- |
| **id** | BIGINT UNSIGNED | Yes | *Auto-Increment* | Primary Key. |
| **user_id** | BIGINT UNSIGNED | Yes | | Foreign key to `users.id`. |
| **section_id** | BIGINT UNSIGNED | Yes | | Foreign key to `onboarding_journey_sections.id`. |
| **score** | SMALLINT | Yes | | Percentage score (0-100). |
| **passed** | BOOLEAN | Yes | | True if the score meets or exceeds the pass threshold. |
| **answers** | JSONB | Yes | | Array of selected options: `[{"question_id": 1, "selected": 0, "correct": true}]`. |
| **created_at** | TIMESTAMP | Yes | | Attempt timestamp. |

---

## 5. Relationships Section

This section details the primary relationship patterns utilized in the database.

### 5.1 One-to-One Relationships
*   **`users` ↔ `user_position_assignments` (Active Primary):** While a user can have multiple positions over time (stored as history), at any single point in time, a user is associated with exactly one primary active position assignment (`is_primary = true` and `ended_at IS NULL`). This active assignment determines their primary position.

### 5.2 One-to-Many Relationships
These are standard parent-child foreign key relationships. Key examples include:
*   **`blueprints` → `blueprint_stages`:** A blueprint can define multiple sequential execution stages.
*   **`blueprint_stages` → `blueprint_sub_stages`:** A stage can contain multiple sub-stages (checklist items).
*   **`tasks` → `task_stage_instances`:** A task instance spawns multiple stage instances as it executes.
*   **`task_stage_instances` → `task_sub_stage_instances`:** A stage instance runs multiple sub-stage instances.
*   **`task_stage_instances` → `task_stage_assignments`:** A stage instance can have multiple assignees.

### 5.3 Many-to-Many Relationships
Implemented using intermediary junction tables:
*   **`users` ↔ `positions` via `user_position_assignments`:** Connects users to positions while tracking their assignment history (start date, end date).
*   **`tasks` ↔ `users` via `task_confidential_participants`:** Manages access to confidential tasks, linking multiple authorized users to a task.

### 5.4 Polymorphic Relationships
Polymorphic relationships allow a table to link to multiple target entities without separate foreign keys.
*   **`documents` (`entity_type` + `entity_id`):** Links attachments to their parent entities:
    *   `entity_type = 'task'` → `tasks.id`
    *   `entity_type = 'comment'` → `comments.id`
    *   `entity_type = 'stage_output'` → `task_stage_instances.id`
    *   `entity_type = 'help_article'` → `help_articles.id`
*   **`notifications` (`notifiable_type` + `notifiable_id`):** Associates notifications with their source:
    *   `notifiable_type = 'task'` → `tasks.id`
    *   `notifiable_type = 'stage_instance'` → `task_stage_instances.id`
    *   `notifiable_type = 'escalation'` → `escalations.id`
*   **`audit_events` (`entity_type` + `entity_id`):** Logs audits across all system entities:
    *   `entity_type = 'task'` → `tasks.id`
    *   `entity_type = 'blueprint'` → `blueprints.id`
    *   `entity_type = 'user'` → `users.id`
    *   `entity_type = 'delegation'` → `delegations.id`

### 5.5 Self-Referencing Relationships
Self-referencing tables reference their own primary key to build hierarchies or chains:
*   **`departments.parent_department_id` → `departments.id`:** Builds the organizational tree (Sectors → Directorates → Sections → Units).
*   **`positions.reports_to_position_id` → `positions.id`:** Defines reporting lines for escalation routing.
*   **`comments.parent_comment_id` → `comments.id`:** Supports single-level comment threads.
*   **`documents.parent_document_id` → `documents.id`:** Links document versions back to their original file.

### 5.6 Historical and Audit Relationships
*   **`user_position_assignments`:** Tracks all position transfers over time. Old assignments are retained with an `ended_at` timestamp.
*   **`position_capability_grants` / `user_capability_grants`:** Modifications to capabilities do not update existing rows. Instead, the active row is updated with a `revoked_at` timestamp and a new grant row is created, preserving the audit trail.
*   **`audit_events`:** An append-only table logging all system events. Modifying or deleting records is prevented.

---

## 6. System Lifecycles

This section details the lifecycles of key system domains, tracking their status transitions and execution rules.

### 6.1 Blueprint Lifecycle
```
[Draft] ──(Publish/Active)──> [Active] ──(First Task Launched)──> [Locked (Immutable)]
  │                                                                 │
  └──(Delete)──> [Deleted] <────────────────────────────────────────┘
```
1.  **Draft:** Blueprint is created and is editable. New tasks cannot be launched.
2.  **Active:** Blueprint is available for use. Users can launch tasks from it.
3.  **Locked:** Triggered when the first task is launched. The blueprint becomes immutable. To make changes, users use a "duplicate-to-edit" workflow.
4.  **Deleted:** The blueprint is soft-deleted. Existing running tasks continue, but new launches are blocked.

### 6.2 Task Lifecycle
```
[Draft] ──(Launch)──> [Active] ──(Suspend)──> [Suspended]
  │                     │                         │
  │                     ├──(Complete)─> [Completed] <─(Resume)
  │                     │
  │                     └──(Cancel)───> [Cancelled]
  └──(Delete)─> [Deleted]
```
1.  **Draft:** Task is created, and properties (due date, manual assignees) are configured. SLA timers are inactive.
2.  **Active:** Task is launched. The current stage is initialized, and SLA timers begin.
3.  **Suspended:** Task is paused by an authorized user. Active SLA timers are paused, and elapsed time is recorded.
4.  **Completed:** The final stage is finished. The task is closed, and overall completion metrics are logged.
5.  **Cancelled:** The task is aborted. A justification reason is required, and all running SLA timers are stopped.
6.  **Deleted:** The task is soft-deleted and removed from dashboards, but retained in the database for auditing.

### 6.3 SLA Timer Lifecycle
```
[Running] ──(Threshold Met)──> [Warning] ──(Deadline Met)──> [Breached]
    │                             │                            │
    └──(Pause)──> [Paused] <──────┘                            └──(Escalate)─> [Escalated]
                     │
                     └──(Resume)──> [Running]
```
1.  **Running:** Timer starts when a stage or sub-stage becomes active. The deadline is calculated using the working calendar and holidays.
2.  **Warning:** Triggered when the warning threshold (e.g. 75% elapsed) is reached. Alerts are sent to assignees.
3.  **Breached:** The deadline is reached without stage completion. The status changes to breached, and the escalation workflow is triggered.
4.  **Completed:** Stage work is submitted. The timer stops, and SLA performance metrics are recorded.
5.  **Paused:** Triggered if the task is suspended. The countdown is paused, and the elapsed time is stored.

### 6.4 Escalation Lifecycle
```
[Triggered] ──(Auto/Manual Resolution)──> [Open / Pending Manager] ──(Action Taken)──> [Resolved]
```
1.  **Triggered:** SLA breach or manual escalation occurs.
2.  **Open:** The escalation is logged and routed to the manager's dashboard based on the reporting structure. Notifications are dispatched.
3.  **Resolved:** The manager addresses the escalation and logs a resolution note, closing the ticket.

### 6.5 Delegation Lifecycle
```
[Created] ──(Current Time >= Start)──> [Active (Routes to Delegate)] ──(Current Time >= End)──> [Expired/Inactive]
```
1.  **Created:** A delegation rule is defined with start and end times.
2.  **Active:** The current date falls within the delegation window. Incoming task assignments are automatically routed to the delegate, recording the delegation details on the assignment.
3.  **Expired:** The end date is reached. Tasks revert to the original assignee.

### 6.6 Audit Lifecycle
```
[User/System Action] ──(Observer Intercept)──> [Queue Event] ──(DB Write)──> [Append-Only DB Record]
```
1.  **Intercept:** User or system actions trigger a database event (e.g. updating a task, overriding an assignment).
2.  **Log:** The system records the user details, IP, browser user agent, and a before/after data snapshot.
3.  **Write:** The audit log is saved as an append-only record in the database. Updates or deletions are blocked.

### 6.7 Help Center Lifecycle
```
[Draft / Unpub] ──(Publish)──> [Published (Visible to all)] ──(Edit/Update)──> [Published (Immediate update)]
                                     │
                                     └──(Unpublish)──> [Draft]
```
1.  **Draft:** The article is created. Visible only to users with the `helpcenter.manage` capability.
2.  **Published:** The article is made visible to all users. FTS search indexes are updated.
3.  **Deleted:** The article is soft-deleted. It is hidden from search and browsing but retained in the database.

### 6.8 Onboarding Journey Lifecycle
```
[IAM Login] ──(Detect Capabilities)──> [Initialize Progress] ──(Guided Walkthrough)──> [Quiz Knowledge Check]
                                                                                               │
  [Completed Journey] <──(Score >= Pass Threshold)── [Pass / Fail Result] <────────────────────┘
```
1.  **Detection:** A user logs in. The system evaluates the user's capabilities against the onboarding rules.
2.  **Initialization:** The user's onboarding progress is initialized, starting at the first section.
3.  **Walkthrough:** The guided UI tour displays. Step progress is recorded as the user completes tasks.
4.  **Quiz:** The user completes a knowledge check quiz at the end of each section.
    *   **Pass:** If the score meets the threshold, the section is completed.
    *   **Fail:** The user is prompted to retry the quiz.
5.  **Completion:** The user completes all sections. The onboarding journey is marked as finished.

---

## 7. Future V2 Extensions and Schema Alignment

This section outlines how the schema is designed to support upcoming V2 features.

### 7.1 Help Center Version History
*   **V2 Requirement:** Track edits to help articles, showing authors, timestamps, and diffs.
*   **Schema Fit:** A new table `help_article_versions` will be introduced:
    ```sql
    CREATE TABLE help_article_versions (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        article_id BIGINT REFERENCES help_articles(id),
        author_user_id BIGINT REFERENCES users(id),
        version_number SMALLINT NOT NULL,
        title_en VARCHAR(255),
        title_ar VARCHAR(255) NOT NULL,
        body_en TEXT,
        body_ar TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL
    );
    ```
    `help_articles` will maintain the current active version, and edits will push historic states to the versions table.

### 7.2 Help Center Feedback
*   **V2 Requirement:** Allow users to rate articles as helpful or not helpful.
*   **Schema Fit:** A feedback table will link users and articles:
    ```sql
    CREATE TABLE help_article_feedback (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        article_id BIGINT REFERENCES help_articles(id),
        user_id BIGINT REFERENCES users(id),
        is_helpful BOOLEAN NOT NULL,
        comment TEXT,
        created_at TIMESTAMP NOT NULL,
        UNIQUE(article_id, user_id)
    );
    ```

### 7.3 Contextual Help Links
*   **V2 Requirement:** Associate help articles with specific system pages or UI states.
*   **Schema Fit:** A mapping table will link articles to UI route names or element selectors:
    ```sql
    CREATE TABLE help_contextual_links (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        article_id BIGINT REFERENCES help_articles(id),
        route_name VARCHAR(150) NOT NULL UNIQUE,
        ui_element_selector VARCHAR(255),
        created_at TIMESTAMP NOT NULL
    );
    ```

### 7.4 Detailed View Analytics
*   **V2 Requirement:** Track details on article views, including readers, duration, and search terms.
*   **Schema Fit:** A detailed log table will track article access:
    ```sql
    CREATE TABLE help_article_views (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        article_id BIGINT REFERENCES help_articles(id),
        user_id BIGINT REFERENCES users(id),
        device_type VARCHAR(50),
        session_id VARCHAR(100),
        viewed_at TIMESTAMP NOT NULL
    );
    ```

### 7.5 Multi-Channel Notifications
*   **V2 Requirement:** Support SMS and WhatsApp alerts.
*   **Schema Fit:** A `notification_preferences` table will define user channels:
    ```sql
    CREATE TABLE user_notification_channels (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        user_id BIGINT REFERENCES users(id),
        channel_type TINYINT NOT NULL, -- 1=in_app, 2=email, 3=sms, 4=whatsapp
        enabled BOOLEAN NOT NULL DEFAULT TRUE,
        UNIQUE(user_id, channel_type)
    );
    ```
    The Laravel notification engine will read these preferences to route notifications accordingly.

### 7.6 Blueprint Versioning
*   **V2 Requirement:** Track blueprint versions, allowing updates to propagate or run side-by-side.
*   **Schema Fit:** We will add `version_number` and `parent_blueprint_id` columns to `blueprints`:
    ```sql
    ALTER TABLE blueprints ADD COLUMN version_number SMALLINT NOT NULL DEFAULT 1;
    ALTER TABLE blueprints ADD COLUMN parent_blueprint_id BIGINT REFERENCES blueprints(id);
    ```
    This supports version history on blueprints, transitioning from the duplicate-to-edit model to a structured version tree.

### 7.7 Committee Management
*   **V2 Requirement:** Assign stages or tasks to committees rather than specific individuals.
*   **Schema Fit:** We will introduce `committees` and `committee_members` tables:
    ```sql
    CREATE TABLE committees (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        name_en VARCHAR(255),
        name_ar VARCHAR(255) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP NOT NULL
    );

    CREATE TABLE committee_members (
        id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
        committee_id BIGINT REFERENCES committees(id),
        position_id BIGINT REFERENCES positions(id),
        member_role TINYINT NOT NULL, -- 1=chair, 2=secretary, 3=member
        joined_at TIMESTAMP NOT NULL
    );
    ```
    `blueprint_stages.assignment_type` will add `4 = committee`, and the execution engine will resolve assignments to all active committee members.

### 7.8 Dynamic Stage Branching
*   **V2 Requirement:** Allow tasks to branch dynamically based on field values or approval decisions.
*   **Schema Fit:** We will add a condition payload to `blueprint_stage_transitions`:
    ```sql
    ALTER TABLE blueprint_stage_transitions ADD COLUMN transition_condition JSONB;
    ```
    The condition JSON (e.g., `{"field": "total_amount", "operator": ">", "value": 100000}`) will be parsed by the execution engine to determine the next stage.

---

*Document version: 1.0*  
*Next: Multi-Tenancy Strategy Validation*
