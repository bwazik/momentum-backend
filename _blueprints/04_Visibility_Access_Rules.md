# Visibility & Access Rules

## Configurable Task Lifecycle Management Platform

> **Phase:** System Design - Access Model Definition
>
> **Input:** Feature Inventory v1, Module Boundary Map v1
>
> **Output:** Configurable ABAC rules + ERD implications
>
> **Next:** ERD

---

## Purpose

This document defines how access works before the ERD is produced.

The platform must be reusable across ministries, hospitals, universities, private companies, and semi-government entities. Therefore, business titles such as Minister, Director, Dean, CEO, Vice President, Follow-Up Specialist, and Department Head must not be hardcoded as system roles.

The access model is based on five concepts:

1. **Account types** - small fixed technical categories.
2. **Positions** - tenant-configurable job slots in the organization structure.
3. **Authority grades** - tenant-defined seniority levels attached to positions.
4. **Capabilities** - named permissions assigned to positions or users.
5. **Relationship-based access** - visibility derived from task/stage participation.

---

## Section 1 - Core Model Decision

### Do Not Hardcode Business Roles

The following must not be fixed system roles:

- Organization Executive
- Department Director
- Follow-Up Specialist
- Stage Assignee

These are business personas, not universal platform roles.

Different tenants may use different titles:

| Tenant Type | Senior Positions | Department Leadership | Monitoring Function |
|---|---|---|---|
| Ministry | Minister, Undersecretary | Director | Follow-Up Specialist |
| University | President, Dean | Department Head | Dean's Office Coordinator |
| Hospital | CEO, Medical Director | Department Chair | Quality / Operations Coordinator |
| Company | CEO, VP | General Manager | PMO Analyst |

The system should not care what the title is. It should care what the user is allowed to do.

---

## Section 2 - Account Types

Account type is a fixed technical classification stored on the user account.
It describes how the account exists in the platform, not the user's business authority.

| Account Type | Purpose |
|---|---|
| `internal_user` | Normal employee or staff member inside the tenant organization. |
| `tenant_admin` | Tenant-side system administrator who configures users, org structure, blueprints, and permissions. |
| `external_auditor` | External read-only account that receives explicit audit grants. |
| `platform_admin` | SaaS operator account for platform maintenance. Optional for product operation, not tenant business use. |

### Rules

- Most users are `internal_user`.
- A tenant admin may also hold a normal business position, but admin power comes from account type or explicit admin capabilities.
- External auditors are not part of the tenant's internal position hierarchy.
- Platform admins do not participate in tenant workflows.

---

## Section 3 - Positions

A position is a tenant-configurable job slot. It exists independently of the user occupying it.

Examples:

- Minister
- Undersecretary
- Director of Legal Affairs
- Dean of Engineering
- Hospital Department Chair
- CEO
- VP of Operations
- General Manager
- Employee

### Position Attributes

Each position should support:

```text
id
tenant_id
department_id
title_en
title_ar
reports_to_position_id
authority_grade_id
is_department_head
is_active
created_at
updated_at
```

### Position Assignment

Users are assigned to positions through a history table, not a direct mutable column only.

```text
user_position_assignments
id
tenant_id
user_id
position_id
started_at
ended_at
is_primary
```

MVP recommendation: one active primary position per user.

Future option: allow multiple concurrent positions only if there is a real tenant need, because it complicates visibility, escalation, workload, and audit interpretation.

---

## Section 4 - Authority Grades

Authority grade is a configurable seniority level attached to a position.

Example government setup:

| Grade Rank | Example Title |
|---|---|
| 1 | Minister |
| 2 | Undersecretary |
| 3 | Assistant Undersecretary |
| 4 | Director |
| 5 | Section Head |
| 6 | Team Lead |
| 7 | Employee |

Example university setup:

| Grade Rank | Example Title |
|---|---|
| 1 | President |
| 2 | Vice President |
| 3 | Dean |
| 4 | Vice Dean |
| 5 | Department Head |
| 6 | Faculty / Staff |

Example company setup:

| Grade Rank | Example Title |
|---|---|
| 1 | CEO |
| 2 | Executive VP |
| 3 | VP |
| 4 | General Manager |
| 5 | Manager |
| 6 | Employee |

### Authority Grade Attributes

```text
authority_grades
id
tenant_id
rank
name_en
name_ar
description
```

Lower rank means higher authority.

Authority grades should support rules such as:

- director grade and above can override stage/sub-stage assignment
- grade 2 and above can view executive analytics
- escalation moves to the reporting position's manager

However, authority grade alone is not enough for all access decisions. Specific capabilities are still required.

---

## Section 5 - Capabilities

Capabilities are named permissions used by the IAM policy engine.

Capabilities may be assigned to:

- positions
- individual users
- account types
- scoped grants

Position-based capability grants are the default. User-specific grants are exceptions and should be audit-sensitive.

### MVP Capability Catalog

| Capability | Meaning |
|---|---|
| `task.view.organization` | Can view tasks across the whole tenant, subject to classification rules. |
| `task.view.department_touched` | Can view tasks that have touched the user's department. |
| `task.view.follow_up_scope` | Can view active tasks inside assigned monitoring scopes. |
| `task.view.own_participation` | Can view tasks the user initiated, currently owns, or previously owned. |
| `task.classify.confidential` | Can create or mark a task as confidential. |
| `task.confidential.view_metadata` | Can discover confidential task metadata without viewing full content. |
| `task.confidential.view_override` | Can open confidential task content through a justified, audited override. |
| `task.confidential.manage_participants` | Can add or remove named confidential participants within granted scope. |
| `task.override_assignment` | Can reassign one or more active stage/sub-stage assignees with a mandatory reason. |
| `task.cancel` | Can cancel active tasks with a mandatory reason. |
| `task.suspend_resume` | Can suspend or resume tasks. |
| `blueprint.view_library` | Can browse the Blueprint library. |
| `blueprint.create.organization` | Can create organization-wide Blueprints. |
| `blueprint.create.department` | Can create department-scoped Blueprints. |
| `blueprint.manage` | Can activate, deactivate, duplicate, or lock/manage Blueprints. |
| `analytics.view.organization` | Can view organization-wide analytics. |
| `analytics.view.department` | Can view department-level analytics. |
| `analytics.view.individuals_in_department` | Can view individual employee metrics inside own department. |
| `iam.manage_users` | Can create, deactivate, and transfer users. |
| `iam.manage_positions` | Can manage departments, positions, reporting lines, and grades. |
| `iam.manage_capabilities` | Can assign capabilities and permission templates. |
| `audit.view_task` | Can view task-level audit trail for visible tasks. |
| `audit.view_system` | Can view system-wide user activity logs. |
| `audit.create_grant` | Can create external audit grants. |
| `onboarding.view_department_status` | Can view onboarding completion status for users in scope. |

---

## Section 6 - Capability Grant Tables

The ERD should support both reusable permission templates and direct grants.

```text
capabilities
id
key
name_en
name_ar
description
is_system_defined
```

```text
position_capability_grants
id
tenant_id
position_id
capability_id
scope_type
department_id
granted_by_user_id
granted_at
revoked_at
```

```text
user_capability_grants
id
tenant_id
user_id
capability_id
scope_type
department_id
granted_by_user_id
granted_at
revoked_at
reason
```

### Scope Types

| Scope Type | Meaning |
|---|---|
| `tenant` | Applies to the whole tenant organization. |
| `own_department` | Applies to the user's current primary department. |
| `specific_department` | Applies to a configured department. |
| `department_tree` | Applies to a department and its children. |
| `own_tasks` | Applies only to tasks related to the user. |
| `audit_grant` | Applies only through an explicit audit grant. |

---

## Section 7 - Follow-Up / Monitoring Scopes

Follow-up is not a hardcoded role.

It is a monitoring capability plus a configured scope.

```text
monitoring_scope_grants
id
tenant_id
user_id
scope_type
department_id
blueprint_category_id
granted_by_user_id
granted_at
revoked_at
```

### Rule

A user can access the follow-up board only if they have:

```text
task.view.follow_up_scope
```

and an active monitoring scope grant.

This lets each tenant name the function differently:

- Follow-Up Specialist
- PMO Analyst
- Operations Coordinator
- Dean's Office Coordinator
- Quality Monitor
- Compliance Tracker

---

## Section 8 - Relationship-Based Visibility

Some visibility must be derived from task relationships, not from position titles.

### Task Initiator

The task initiator is not a role.

Any user who creates a task retains read access to that task for its lifecycle, unless a stricter classification rule denies it.

```text
tasks.initiator_user_id
```

### Current Stage/Sub-stage Assignee

An active stage or sub-stage assignee can view and act on the active step they are assigned to.

```text
task_stage_assignments.user_id
```

### Past Stage/Sub-stage Assignee

A user who was assigned to a stage or sub-stage in the past retains read access to the task history.

### Named Confidential Participant

Confidential tasks are visible only to explicitly named participants, plus technical admins.

```text
task_confidential_participants
id
tenant_id
task_id
user_id
added_by_user_id
added_at
```

### Confidential Governance Participant

Tenants may configure mandatory governance positions that are automatically added to confidential tasks.

Examples:

- Minister's Office
- CEO
- General Counsel
- Chief Compliance Officer
- Internal Audit Head
- Data Protection Officer

```text
confidential_governance_participants
id
tenant_id
position_id
scope_type
department_id
applies_to_classification_level
created_by_user_id
created_at
revoked_at
```

This prevents a lower-authority user from creating a confidential task that is invisible to every senior governance function, while still preserving need-to-know access.

### External Auditor

External auditors see nothing by default.

They require an explicit audit grant.

```text
audit_grants
id
tenant_id
external_auditor_user_id
granted_by_user_id
date_range_start
date_range_end
department_id
granted_at
revoked_at
```

---

## Section 9 - Task Visibility Rules

The core question: which tasks appear in a user's board, search results, and task views?

### Rule T-1: Tenant Admin / Technical Admin

Tenant admins with the relevant admin capability can access tenant data needed for administration.

Technical access must be logged and should not be treated as normal business visibility.

### Rule T-2: Organization-Wide Visibility

A user can see all tenant tasks when they have:

```text
task.view.organization
```

Subject to classification rules.

This replaces the hardcoded `org_executive` role.

Examples of positions that may receive this capability:

- Minister
- Undersecretary
- CEO
- University President
- Hospital CEO

### Rule T-3: Department-Touched Visibility

A user can see tasks that have ever touched their department when they have:

```text
task.view.department_touched
```

A task touches a department when:

- it was initiated by someone in that department
- its current active stage is owned by someone in that department
- any previous stage instance was owned by someone in that department

This replaces the hardcoded `department_director` role.

Examples of positions that may receive this capability:

- Department Director
- Dean
- General Manager
- Department Head
- Hospital Department Chair

### Rule T-4: Follow-Up Scope Visibility

A user can see active tasks inside their monitoring scope when they have:

```text
task.view.follow_up_scope
```

and an active row in:

```text
monitoring_scope_grants
```

Default follow-up visibility includes:

- active tasks
- overdue tasks
- at-risk tasks
- suspended tasks

Completed and cancelled tasks are excluded from follow-up scope by default unless a separate archive/reporting capability allows them.

### Rule T-5: Own Participation Visibility

A user can see a task when any of these is true:

- user is the task initiator
- user is a current active stage or sub-stage assignee
- user was a past stage or sub-stage assignee
- user is explicitly named as a participant

This replaces the hardcoded `stage_owner` role.

### Rule T-6: External Audit Visibility

An external auditor sees no tasks by default.

An external auditor can see only completed or archived tasks covered by an active audit grant:

- grant is active
- task completion/archive date is within grant date range
- task department is within grant scope, if a department scope is specified

External auditors cannot see active in-progress tasks.

---

## Section 10 - Classification Rules

Classification level is applied after normal visibility rules.

```text
tasks.classification_level
```

Default levels:

- `public`
- `internal`
- `confidential`

Tenant admins may rename display labels, but the system behavior should remain stable.

| Classification | Effect |
|---|---|
| `public` | No additional restriction. Normal visibility rules apply. |
| `internal` | Blocks lateral uninvolved department visibility. Participants, department-touched viewers, follow-up scope viewers, and organization-wide viewers may still see it according to their grants. |
| `confidential` | Visible only to named participants, task initiator, current/past stage or sub-stage assignees, configured governance participants, and technical tenant admins. Organization-wide visibility does not automatically bypass confidentiality. Optional override access may be enabled by tenant policy. |

### Confidential Rule

For confidential tasks, organization-wide visibility is not enough.

The user must be:

- task initiator
- current or past stage/sub-stage assignee
- explicitly listed in `task_confidential_participants`
- current holder of a configured confidential governance participant position
- tenant admin using technical/admin access
- authorized override user using `task.confidential.view_override`

This prevents a configurable "CEO" or "Minister" capability from accidentally bypassing sensitive need-to-know restrictions, while still giving tenants a controlled way to prevent confidential tasks from being hidden from accountable leadership.

### Confidential Creation Rule

Not every user should be able to create confidential tasks.

Creating or changing a task to `confidential` requires:

```text
task.classify.confidential
```

If a tenant wants any employee to request confidentiality, the safer pattern is:

1. User creates the task as `internal`.
2. User requests confidential classification.
3. A user with `task.classify.confidential` approves the classification change.

### Confidential Metadata Rule

Some tenants need senior leaders to know that a confidential task exists without exposing the full content.

The capability:

```text
task.confidential.view_metadata
```

allows a user to see limited metadata only:

- task ID
- title or redacted title, based on tenant policy
- owning department
- current status
- current responsible position
- due date / SLA health
- classification level

It does not allow viewing descriptions, comments, attachments, stage outputs, or audit details.

### Confidential Override Rule

The capability:

```text
task.confidential.view_override
```

allows selected high-authority or governance positions to open confidential task content even when they are not named participants.

This must not be a silent bypass. Override access requires:

- active capability grant within scope
- mandatory reason entered before access
- immutable audit event
- optional notification to compliance, internal audit, tenant admin, or task governance owner
- optional time-limited access window

Recommended default: disabled unless the tenant explicitly enables it.

Suitable positions for this capability may include:

- Minister / CEO / President
- General Counsel
- Chief Compliance Officer
- Internal Audit Head
- Data Protection Officer

Avoid granting this broadly to every high authority grade. Confidential override is a governance control, not a normal executive dashboard permission.

### Government and Private-Sector Defaults

Recommended government default:

- confidential content is named-access only
- Minister's Office, Internal Audit, or Legal may be configured as governance participants by department or Blueprint category
- override access is enabled only for audit/compliance-approved positions
- every override requires reason and audit logging

Recommended private-sector default:

- CEO, General Counsel, Compliance, or Internal Audit may receive metadata visibility
- content override is limited to Legal, Compliance, Internal Audit, or CEO-level positions
- HR, legal privilege, whistleblower, patient, and investigation workflows should use governance participants rather than broad executive access

---

## Section 11 - Stage Detail Rules

### Rule S-1: Full Stage History

Anyone who can view a task can view the full stage history unless a future document-level restriction applies.

### Rule S-2: Full Blueprint Snapshot

Anyone who can view a task can view the Blueprint snapshot that governs that task.

### Rule S-3: Stage Output Visibility

Completed stage outputs are visible to all users who can view the task.

V2 document-level restrictions may narrow access for sensitive attachments.

### Rule S-4: Stage Actions

Only current active stage or sub-stage assignees can:

- submit output for their assigned step
- advance the task when the configured completion rule allows them to do so
- return the task when the configured completion rule allows them to do so

Override is separate. A user may override stage/sub-stage assignment only if they have:

```text
task.override_assignment
```

and the override applies within their granted scope.

### Rule S-5: Comments

Task comments are visible to all users who can view the task.

Internal department-only comments are deferred to V2.

---

## Section 12 - Blueprint Access Rules

| Action | Required Capability |
|---|---|
| Browse Blueprint library | `blueprint.view_library` |
| Create organization-wide Blueprint | `blueprint.create.organization` |
| Create department-scoped Blueprint | `blueprint.create.department` |
| Edit unlocked Blueprint | `blueprint.manage` |
| Activate / deactivate Blueprint | `blueprint.manage` |
| Duplicate Blueprint | `blueprint.manage` |
| View Blueprint definition attached to visible task | Task visibility is enough |

### Blueprint Lock Rule

Once any task has been launched under a Blueprint, its stage definitions, transitions, and SLA rules are read-only.

The only edit path is:

- Duplicate Blueprint in MVP
- Version Blueprint in V2

---

## Section 13 - Analytics Access Rules

| Dashboard / Report | Required Capability |
|---|---|
| Executive dashboard | `analytics.view.organization` |
| Department performance | `analytics.view.department` | Can view reports and charts for the scoped department and its child departments. |
| `helpcenter.manage` | Can create, edit, publish, unpublish, and delete help articles. |
| `helpcenter.view` | Can browse and read published help articles. Typically granted to all internal users. |
| Bottleneck view | `analytics.view.department` or `analytics.view.organization` |
| Task aging report | `analytics.view.department`, `analytics.view.organization`, or follow-up scope |
| Individual performance in department | `analytics.view.individuals_in_department` |
| My personal workspace | own participation access |

### Individual Metrics Rule

Individual employee performance metrics require explicit capability.

Department-scoped individual metrics should be limited to the user's department or granted department scope.

---

## Section 14 - Audit Access Rules

| Audit Action | Required Access |
|---|---|
| View audit trail of a visible task | `audit.view_task` plus task visibility |
| View system-wide user activity | `audit.view_system` |
| Export audit log | V2, explicit export capability |
| Create audit grant | `audit.create_grant` |
| External auditor task audit view | Active `audit_grants` row |
| Confidential metadata view | automatic audit event recommended |
| Confidential content override | mandatory audit event with reason |

External auditors can view audit trails only for tasks covered by their audit grant.

Confidential override events should be easy to review separately from ordinary task views. They are governance-sensitive and should show who accessed the task, when, why, and under which capability grant.

---

## Section 15 - Document and Attachment Rules

### Rule D-1: Attachments Inherit Task Visibility

Documents attached to a task are visible to users who can view the task.

### Rule D-2: Document-Level Restrictions

V2 document-level restrictions may limit access to explicitly named users, positions, or capabilities.

### Rule D-3: Stage Output Documents

Documents attached as stage output are visible to all users who can view the task, unless a V2 document restriction applies.

---

## Section 16 - Onboarding Access Rules

Onboarding should not depend on hardcoded roles.

The onboarding module should select a journey using:

- account type
- assigned position
- authority grade
- capabilities
- whether the user owns stages
- whether the user has monitoring scope

Example journey mapping:

| Detected Access Pattern | Journey |
|---|---|
| `task.view.organization` or `analytics.view.organization` | Executive journey |
| `task.view.department_touched` or `analytics.view.department` | Department leadership journey |
| `task.view.follow_up_scope` | Follow-up / monitoring journey |
| current or potential stage/sub-stage assignee | Stage assignee / employee journey |
| `tenant_admin` or admin capabilities | Admin journey |

Tenants may rename journey labels in V2.

---

## Section 17 - Help Center Access Rules

| Action | Required Access |
|---|---|
| Browse published articles | `helpcenter.view` (recommended: granted to all `internal_user` accounts by default) |
| Search articles | `helpcenter.view` |
| View published article content | `helpcenter.view` |
| Create article | `helpcenter.manage` |
| Edit article | `helpcenter.manage` |
| Publish / unpublish article | `helpcenter.manage` |
| Delete article | `helpcenter.manage` |
| Manage article categories | `helpcenter.manage` |

### Rule HC-1: All Internal Users Can Read

By default, every `internal_user` should be granted `helpcenter.view` so that all employees have access to platform guidance. Tenant admins may revoke this if needed.

### Rule HC-2: Article Management is Capability-Controlled

Article authoring, editing, publishing, and deletion require `helpcenter.manage`. This capability should be granted to positions responsible for platform administration, training, or knowledge management.

### Rule HC-3: External Auditors Cannot Access Help Center

External auditors do not need access to internal help articles. `helpcenter.view` should not be granted to `external_auditor` accounts.

### Rule HC-4: Unpublished Articles

Unpublished (draft) articles are visible only to users with `helpcenter.manage`.

---

## Section 18 - ABAC Policy Summary

Plain-language policy logic for the IAM module:

```text
RULE: view_task(user, task)
  ALLOW if user has technical tenant admin access for the task tenant

  ALLOW if user has capability task.view.organization
         AND task.tenant_id = user.tenant_id

  ALLOW if user has capability task.view.department_touched
         AND task_touches_user_department(task, user)

  ALLOW if user has capability task.view.follow_up_scope
         AND task_in_user_monitoring_scope(task, user)
         AND task.status IN (active, overdue, at_risk, suspended)

  ALLOW if user_is_task_initiator(user, task)

  ALLOW if user_is_current_or_past_stage_assignee(user, task)

  ALLOW if user_is_named_task_participant(user, task)

  ALLOW if user_is_confidential_governance_participant(user, task)

  ALLOW if user.account_type = external_auditor
         AND valid_audit_grant_covers(user, task)
         AND task.status IN (completed, archived)

  DENY if task.classification_level = confidential
        AND user is not technical tenant admin
        AND user is not task initiator
        AND user is not current/past stage/sub-stage assignee
        AND user is not named confidential participant
        AND user is not confidential governance participant
        AND user is not using an approved confidential override

  DENY if task.classification_level = internal
        AND user has only lateral visibility
        AND user is not a participant
        AND task has not touched user's allowed department/scope

  DEFAULT DENY

RULE: view_confidential_metadata(user, task)
  ALLOW if task.classification_level = confidential
         AND user has capability task.confidential.view_metadata
         AND capability scope covers task department/scope
  DENY otherwise

RULE: view_confidential_with_override(user, task, reason)
  ALLOW if task.classification_level = confidential
         AND user has capability task.confidential.view_override
         AND capability scope covers task department/scope
         AND reason is present
         AND confidential override audit event is recorded
  DENY otherwise

RULE: classify_task_confidential(user, task)
  ALLOW if user has capability task.classify.confidential
         AND capability scope covers task department/scope
  DENY otherwise

RULE: manage_confidential_participants(user, task)
  ALLOW if user has capability task.confidential.manage_participants
         AND capability scope covers task department/scope
  ALLOW if user is task initiator
         AND tenant policy allows initiator-managed confidential participants
  DENY otherwise

RULE: advance_stage_or_substage(user, stage_or_substage_instance)
  ALLOW if user_is_active_assignee(user, stage_or_substage_instance)
         AND completion_rule_allows_advance(user, stage_or_substage_instance)
  DENY otherwise

RULE: return_stage_or_substage(user, stage_or_substage_instance)
  ALLOW if user_is_active_assignee(user, stage_or_substage_instance)
         AND completion_rule_allows_return(user, stage_or_substage_instance)
  DENY otherwise

RULE: override_stage_assignment(user, stage_or_substage_instance)
  ALLOW if user has capability task.override_assignment
         AND capability scope covers stage_or_substage_instance.owning_department_id
  DENY otherwise

RULE: create_blueprint(user, blueprint)
  ALLOW if blueprint.scope = organization
         AND user has capability blueprint.create.organization
  ALLOW if blueprint.scope = department
         AND user has capability blueprint.create.department
         AND capability scope covers blueprint.department_id
  DENY otherwise

RULE: view_analytics_department(user, department)
  ALLOW if user has capability analytics.view.organization
  ALLOW if user has capability analytics.view.department
         AND capability scope covers department.id
  DENY otherwise

RULE: view_help_article(user, article)
  ALLOW if article.is_published = true
         AND user has capability helpcenter.view
  ALLOW if user has capability helpcenter.manage
  DENY otherwise

RULE: manage_help_article(user)
  ALLOW if user has capability helpcenter.manage
  DENY otherwise
```

---

## Section 19 - ERD Implications

The ERD should not include:

```text
users.role ENUM(system_admin, org_executive, follow_up_specialist, department_director, stage_owner, external_auditor)
```

Instead, the ERD should include the following.

### `users`

```text
id
tenant_id
account_type
name_en
name_ar
email
mobile
employee_id
is_active
preferred_language
created_at
updated_at
```

### `departments`

```text
id
tenant_id
parent_department_id
name_en
name_ar
is_active
created_at
updated_at
```

### `positions`

```text
id
tenant_id
department_id
title_en
title_ar
reports_to_position_id
authority_grade_id
is_department_head
is_active
created_at
updated_at
```

### `authority_grades`

```text
id
tenant_id
rank
name_en
name_ar
description
```

### `user_position_assignments`

```text
id
tenant_id
user_id
position_id
started_at
ended_at
is_primary
```

### `capabilities`

```text
id
key
name_en
name_ar
description
is_system_defined
```

### `position_capability_grants`

```text
id
tenant_id
position_id
capability_id
scope_type
department_id
granted_by_user_id
granted_at
revoked_at
```

### `user_capability_grants`

```text
id
tenant_id
user_id
capability_id
scope_type
department_id
granted_by_user_id
granted_at
revoked_at
reason
```

### `monitoring_scope_grants`

```text
id
tenant_id
user_id
scope_type
department_id
blueprint_category_id
granted_by_user_id
granted_at
revoked_at
```

### `tasks`

```text
id
tenant_id
blueprint_id
title_en
title_ar
description_en
description_ar
priority_id
classification_level
initiator_user_id
status
due_date
created_at
launched_at
suspended_at
resumed_at
completed_at
cancelled_at
archived_at
archived_by_user_id
deleted_at
```

`classification_level` stores tinyint: 1 = public, 2 = internal, 3 = confidential. `status` stores tinyint: 1 = draft, 2 = active, 3 = suspended, 4 = completed, 5 = cancelled. `priority_id` references `task_priorities`. Arabic fields required, English optional (system copies Arabic to English if empty).

### `task_stage_instances`

```text
id
tenant_id
task_id
blueprint_stage_snapshot_id
owning_department_id
assignment_completion_rule
status
entered_at
exited_at
```

The `owning_department_id` must be resolved and stored when the stage is entered.
This supports efficient "task touched department" queries even if the user's position changes later.

### `task_sub_stage_instances`

```text
id
tenant_id
task_id
parent_stage_instance_id
blueprint_sub_stage_snapshot_id
owning_department_id
assignment_completion_rule
status
entered_at
exited_at
```

### `task_stage_assignments`

```text
id
tenant_id
task_id
stage_instance_id
sub_stage_instance_id
user_id
position_id
assignment_role
assigned_at
completed_at
reassigned_at
reassignment_reason
```

`stage_instance_id` is populated for direct stage assignments. `sub_stage_instance_id` is populated for sub-stage assignments. A row in this table represents one assigned user. Multiple rows allow one stage or sub-stage to have multiple assignees.

### `task_confidential_participants`

```text
id
tenant_id
task_id
user_id
added_by_user_id
added_at
```

### `confidential_governance_participants`

```text
id
tenant_id
position_id
scope_type
department_id
blueprint_category_id
applies_to_classification_level
created_by_user_id
created_at
revoked_at
```

This table configures positions that are automatically included in confidential governance access for a scope.

### `confidential_access_events`

```text
id
tenant_id
task_id
user_id
access_type
reason
created_at
```

`access_type` should distinguish at least:

- `metadata_view`
- `content_override`
- `participant_added`
- `participant_removed`

These events may also be emitted into the immutable Audit module. A dedicated table is useful if the product needs quick reporting on confidential access.

### `audit_grants`

```text
id
tenant_id
external_auditor_user_id
granted_by_user_id
date_range_start
date_range_end
department_id
granted_at
revoked_at
```

### `blueprints`

```text
id
tenant_id
category_id
name_en
name_ar
description_en
description_ar
scope
department_id
is_locked
is_active
created_by_user_id
created_at
updated_at
deleted_at
```

`scope` stores tinyint: 1 = organization, 2 = department. `category_id` references `blueprint_categories`.

### `blueprint_categories`

```text
id
tenant_id
name_en
name_ar
display_order
is_active
created_at
updated_at
deleted_at
```

Tenant-defined categories for classifying Blueprints. Each Blueprint belongs to exactly one category.

### `task_priorities`

```text
id
tenant_id
name_en
name_ar
severity_rank
color_code
is_default
is_active
display_order
created_at
updated_at
deleted_at
```

Tenant-configurable priority levels. Replaces hardcoded Routine/Urgent/Critical enum. Tasks reference a priority record.

### `stage_types`

```text
id
tenant_id
name_en
name_ar
is_system_default
is_active
display_order
created_at
updated_at
deleted_at
```

Tenant-configurable stage types. Platform ships with defaults (Action, Review, Approval, Decision, Information Gathering) but tenants may add their own.

### `sla_policies`

```text
id
tenant_id
name_en
name_ar
sla_value
sla_unit
warning_threshold_percentage
is_active
created_at
updated_at
deleted_at
```

`sla_unit` stores tinyint: 1 = hours, 2 = days. Reusable named SLA policies assigned to Blueprint stages.

### `working_calendars`

```text
id
tenant_id
name_en
name_ar
working_days
working_hours_start
working_hours_end
timezone
is_default
created_at
updated_at
```

`working_days` stores which days are working days. Tenant-level for MVP.

### `public_holidays`

```text
id
tenant_id
working_calendar_id
name_en
name_ar
holiday_date
is_recurring
created_at
```

### `external_entities`

```text
id
tenant_id
name_en
name_ar
entity_type
is_active
created_at
updated_at
deleted_at
```

`entity_type` stores tinyint: 1 = government_ministry, 2 = government_authority, 3 = semi_government, 4 = university, 5 = hospital, 6 = private_company, 7 = vendor, 8 = other. Tenant-managed reference table for external reference issuing entities.

### `documents`

```text
id
tenant_id
uploader_user_id
original_filename
storage_path
mime_type
size_bytes
entity_type
entity_id
version_number
parent_document_id
created_at
deleted_at
```

Document metadata table. Actual files stored in configurable object storage (S3/Azure Blob/MinIO). `entity_type` and `entity_id` provide polymorphic attachment (task, comment, stage_output, article).

### `notifications`

```text
id
tenant_id
user_id
type
notifiable_type
notifiable_id
data
read_at
created_at
```

Follows Laravel notification conventions. `data` is JSONB containing notification payload. Persisted with read/unread state.

### `comments`

```text
id
tenant_id
task_id
user_id
parent_comment_id
body
created_at
updated_at
deleted_at
```

`parent_comment_id` supports single-level threading. Replies cannot have sub-replies.

### `help_articles`

```text
id
tenant_id
category_id
title_en
title_ar
body_en
body_ar
is_published
display_order
created_by_user_id
updated_by_user_id
published_at
created_at
updated_at
deleted_at
```

### `help_article_categories`

```text
id
tenant_id
name_en
name_ar
display_order
is_active
created_at
updated_at
deleted_at
```

---

## Section 20 - Decision Summary

Final recommendation:

- Use fixed account types only for technical account categories.
- Use tenant-configurable positions for business titles.
- Use authority grades for seniority and escalation.
- Use capabilities for permissions.
- Use scoped grants for monitoring and cross-department access.
- Use task relationships for initiator, current owner, past owner, and confidential participant visibility.
- Use `task.classify.confidential` to control who can create confidential tasks.
- Use confidential governance participants to prevent sensitive work from being hidden from accountable oversight positions.
- Use confidential override only as a scoped, justified, audited exception.
- Keep external auditor access explicit through audit grants.
- Do not hardcode government-specific roles into `users`.

This model keeps the platform reusable while still supporting the GCC government operating model.

---

## Section 21 - Confirmed Decisions Before ERD

The following decisions are confirmed and should be used as ERD inputs:

1. MVP enforces one active primary position per user.
   - Reason: multiple active positions complicate visibility, escalation, workload, and audit interpretation.

2. User-specific capability grants are allowed in MVP only as exceptions.
   - Requirement: every direct user grant must include grantor, timestamp, scope, revocation state, and reason.

3. Department scope does not implicitly include child departments.
   - Requirement: child inclusion must be explicit through `scope_type`, such as `specific_department` vs. `department_tree`.

4. Confidential tasks do not allow organization-wide viewers by default.
   - Rule: confidential access requires named participation, direct task relationship, configured governance participation, or justified audited override.

5. Tenant admins do not receive normal business visibility by default.
   - Rule: admin access is treated as technical access and audited separately.

6. Confidential override is disabled by default.
   - Rule: it is tenant-configurable and granted only to selected governance positions.

7. Confidential metadata visibility is optional by tenant policy.
   - Default: do not expose confidential metadata unless the tenant configures `task.confidential.view_metadata` for selected positions or governance functions.

8. Multi-tenancy uses separate database per tenant.
   - Reason: strongest isolation model. Each tenant has its own database. A central management database holds tenant registry and platform configuration.

9. Localization uses explicit columns (name_en, name_ar) for MVP.
   - Reason: better indexing, sorting, reporting, search, and schema clarity. If a third language is needed in the future, a dedicated translations table will be introduced.

10. Task titles and descriptions support bilingual storage.
    - Rule: Arabic fields are required. English fields are optional. If English is empty, the system copies Arabic value into the English field automatically.

11. All database enums are stored as tinyint with Laravel Enum Classes.
    - Reason: better database efficiency, easier maintenance, and avoids database-level ENUM type limitations.

12. Task priorities are tenant-configurable reference data.
    - Rule: stored in `task_priorities` table. Platform ships with defaults (Routine, Urgent, Critical) but tenants may customize.

13. Stage types are tenant-configurable reference data.
    - Rule: stored in `stage_types` table. Platform ships with defaults but tenants may add their own.

14. SLA policies are reusable named entities with value and unit.
    - Rule: stored as `sla_value` + `sla_unit` (hours or days). Working calendar is hours-aware with working_hours_start and working_hours_end.

15. Escalation follows position reporting hierarchy.
    - Rule: resolved via `positions.reports_to_position_id`, not department hierarchy.

16. Sub-stages support required/optional designation.
    - Rule: parent stage cannot complete until all required sub-stages are complete. Sub-stages have independent SLA timers.

17. Stage assignments support required/optional and lead designation.
    - Rule: only required assignees participate in completion calculations. Lead assignee designated via assignment_role.

18. Only Stage 1 is validated at task launch.
    - Rule: later stages are resolved when entered. Vacant position at later stage entry blocks entry and alerts admin.

19. Archive is a logical status on the tasks table.
    - Rule: no separate archive tables. Archived tasks remain in the main table with archived_at and archived_by_user_id.

20. Reopening archived tasks (V2) creates a new task linked to the original.
    - Rule: original archived task is never modified. New task uses reopened_from_task_id.

21. Dates stored as Gregorian only.
    - Rule: Hijri conversion is computed at the application/presentation layer.

22. Comments support single-level replies only.
    - Rule: a comment may have replies but replies cannot have sub-replies.

23. Delegation scope uses Blueprint Categories and Stage Types.
    - Rule: not tied to individual Blueprint IDs. Supports category scope, stage type scope, or combination.

24. Documents use pluggable object storage with metadata in database.
    - Rule: database stores metadata only. Actual files in configurable storage provider.

25. Notifications use Laravel notification system with persistent read/unread state.

26. External reference issuing entities use a reference table.
    - Rule: `external_entities` table managed by tenants for consistency.

---

*Document version: 1.0*  
*This document replaces the hardcoded business-role access model.*  
*Next: ERD Design Session*
