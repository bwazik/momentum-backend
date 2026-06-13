# Module Boundary Map

## Configurable Task Lifecycle Management Platform

> **Phase:** System Design — Module Boundaries
>
> **Input:** Feature Inventory v1 (273 features, 21 domains)
>
> **Next:** Visibility & Access Rules Session → ERD

---

## Module Catalog

| Module | Layer | Phase | Primary Responsibility |
| --- | --- | --- | --- |
| **Core** | Foundation | **MVP** | Multi-tenant context, event bus, base models, date and locale utilities, shared traits, soft-delete infrastructure |
| **Platform** | Foundation | **MVP** | Central DB management, tenant provisioning, tenant suspension, platform admin management, impersonation sessions |
| **Organization** | Organization | **MVP** | Org entities, department hierarchy, positions, reporting lines, authority grades, working calendar, public holiday configuration |
| **IAM** | Organization | **MVP** | Users, authentication, delegation, out-of-office status, ABAC policy engine |
| **Blueprint** | Business | **MVP** | Blueprint templates, stage and sub-stage definitions, transition rules, SLA policies per stage/sub-stage, Blueprint library, versioning (V2) |
| **Task** | Business | **MVP** | Task instances, stage/sub-stage lifecycle progression, assignment resolution, external references, comments, sub-tasks (V2), recurring tasks (V2) |
| **Tracking & SLA** | Operational | **MVP** | Stage/sub-stage-level SLA timers, holiday-aware deadline calculation, overdue detection, escalation engine |
| **Notification** | Operational | **MVP** | In-app alerts, email notifications, notification template management, SMS (V2), WhatsApp (V2), user preferences (V2) |
| **Analytics** | Operational | **MVP** | Executive dashboard, director dashboard, bottleneck analysis, task aging report, stage SLA reports, personal workspace views |
| **Onboarding** | Operational | **MVP** | Access-profile-based guided journeys, interactive walkthroughs, knowledge checks, training progress tracking, admin training dashboard |
| **Document** | Infrastructure | **MVP** | File attachments, version history, inline preview, access restriction (V2) |
| **Audit** | Infrastructure | **MVP** | Immutable event log, per-item audit trail, user activity reports, log export (V2) |
| **Search** | Infrastructure | **MVP** | Full-text indexing across tasks, stages, and sub-stages, external reference lookup, Hijri date search, saved searches (V2) |
| **Help Center** | Operational | **MVP** | Article library, bilingual content management, article categories, article search, contextual help links (V2) |
| **Committee** | Business | **V2** | Committees, meetings, minutes, decisions, action items, voting (V3) |

---

## Diagram 1 — Module Layer Architecture

```mermaid
flowchart TD
    classDef f fill:#1B2A4A,color:#EDD88A,stroke:#C8992A,stroke-width:2px
    classDef o fill:#185FA5,color:#fff,stroke:#0D4A8A,stroke-width:1px
    classDef b fill:#1A7F5A,color:#fff,stroke:#116644,stroke-width:1px
    classDef p fill:#854F0B,color:#fff,stroke:#6A3F09,stroke-width:1px
    classDef i fill:#3A3A3A,color:#fff,stroke:#222222,stroke-width:1px

    CORE([Core Foundation])
    PLATFORM([Platform Administration])

    subgraph OL["Organization Layer"]
        ORG([Organization])
        IAM([IAM])
    end

    subgraph BL["Business Layer"]
        BP([Blueprint])
        TASK([Task])
        COM([Committee — V2])
    end

    subgraph PL["Operational Layer"]
        TRACK([Tracking and SLA])
        NOTIF([Notification])
        ANAL([Analytics])
        ONB([Onboarding])
        HC([Help Center])
    end

    subgraph IL["Infrastructure Layer"]
        DOC([Document])
        AUD([Audit])
        SRCH([Search])
    end

    CORE --> ORG
    CORE --> IAM
    CORE --> BP
    CORE --> TASK
    CORE --> COM
    CORE --> TRACK
    CORE --> NOTIF
    CORE --> ANAL
    CORE --> ONB
    CORE --> HC
    CORE --> DOC
    CORE --> AUD
    CORE --> SRCH
    PLATFORM --> CORE

    class CORE,PLATFORM f
    class ORG,IAM o
    class BP,TASK,COM b
    class TRACK,NOTIF,ANAL,ONB,HC p
    class DOC,AUD,SRCH i
```

---

## Diagram 2 — Module Communication (Key Data Flows)

Solid `-->` = data written or action triggered.
Dotted `-.-` = read-only query, no mutation.

```mermaid
flowchart LR
    classDef o fill:#185FA5,color:#fff,stroke:#0D4A8A
    classDef b fill:#1A7F5A,color:#fff,stroke:#116644
    classDef p fill:#854F0B,color:#fff,stroke:#6A3F09
    classDef i fill:#3A3A3A,color:#fff,stroke:#222222

    ORG([Organization])
    IAM([IAM])
    BP([Blueprint])
    TASK([Task])
    TRACK([Tracking and SLA])
    NOTIF([Notification])
    ANAL([Analytics])
    DOC([Document])
    AUD([Audit])
    SRCH([Search])
    HC([Help Center])

    ORG -->|authority grades and position hierarchy| BP
    ORG -->|working days and holiday calendar| TRACK
    ORG --> IAM

    IAM -->|auth and ABAC permissions| BP
    IAM -->|auth and ABAC permissions| TASK
    IAM -->|delegation resolves active assignees| TASK

    BP -->|Blueprint definition snapshot| TASK

    TASK -->|stage/sub-stage entered and exited events| TRACK
    TASK -->|assignment change events| NOTIF
    TASK -->|file attachment requests| DOC
    TASK -->|indexes task content and stage notes| SRCH
    TASK -->|emits all lifecycle events| AUD

    TRACK -->|SLA warning and breach events| NOTIF
    TRACK -->|escalation created events| NOTIF
    TRACK -.->|monitors active stage/sub-stage timers| TASK
    TRACK -->|escalation events| AUD

    ANAL -.->|reads task and stage records| TASK
    ANAL -.->|reads SLA timer and escalation records| TRACK
    ANAL -.->|reads Blueprint definitions for labels| BP
    ANAL -.->|reads org structure for department grouping| ORG

    IAM -->|account type, position, authority grade, capabilities, and scopes| ONB
    ONB -->|journey completion events| AUD

    IAM -->|auth and ABAC permissions| HC
    HC -->|article content indexed| SRCH
    HC -->|article lifecycle events| AUD

    class ORG,IAM o
    class BP,TASK b
    class TRACK,NOTIF,ANAL,ONB,HC p
    class DOC,AUD,SRCH i
```

---

## Diagram 3 — Task Stage State Machine

A single task instance moves through these states. This is what the Task module owns.

```mermaid
flowchart TD
    classDef draft fill:#5D6D7E,color:#fff,stroke:#4A5568
    classDef active fill:#1A5276,color:#fff,stroke:#0D3A5C
    classDef terminal fill:#1A7F5A,color:#fff,stroke:#116644
    classDef negative fill:#922B21,color:#fff,stroke:#7B241C

    DRAFT["DRAFT\nTask created but not launched"]
    STAGE_N["ACTIVE — Stage/Sub-stage N\nAssignees resolved from Blueprint\nSLA timer running"]
    COMPLETED["COMPLETED\nAll stages closed\nAuto-archived"]
    CANCELLED["CANCELLED\nManually terminated\nAuto-archived"]

    DRAFT -->|"Launch task\n(notifies Stage 1 assignees)"| STAGE_N
    DRAFT -->|Cancel draft| CANCELLED
    STAGE_N -->|"Advance\n(completion rule satisfied)"| STAGE_N
    STAGE_N -->|"Return\n(mandatory reason required)"| STAGE_N
    STAGE_N -->|"Cancel\n(Director grade+, mandatory reason)"| CANCELLED
    STAGE_N -->|"Final stage closed"| COMPLETED
    COMPLETED -.->|"Admin reopen — V2"| STAGE_N

    class DRAFT draft
    class STAGE_N active
    class COMPLETED terminal
    class CANCELLED negative
```

---

## Diagram 4 — Blueprint-to-Task Relationship

How a single Blueprint template generates multiple independent task instances.

```mermaid
flowchart LR
    classDef bp fill:#C8992A,color:#1B2A4A,stroke:#A07820,stroke-width:2px
    classDef stage fill:#1A5276,color:#fff,stroke:#0D3A5C
    classDef task fill:#1A7F5A,color:#fff,stroke:#116644
    classDef breach fill:#922B21,color:#fff,stroke:#7B241C

    BP["Blueprint\nMinisterial Directive Response\n(Template — reused for every instance)"]

    BS1["Stage 1 Definition\nDirector Assignment\nAssignees: Director grade+\nSLA: 4 working hours"]
    BS2["Stage 2 Definition\nDraft Response\nAssignees: One or more specialists\nSLA: 3 working days"]
    BS3["Stage 3 Definition\nLegal Review\nSub-stages allowed\nAssignees: Legal team\nSLA: 2 working days"]
    BS4["Stage 4 Definition\nUndersecretary Sign-off\nAssignees: Undersecretary position\nSLA: 1 working day"]

    T1["Task Instance A\nRef: وارد-2026-00412\nCurrently at Stage 3\nElapsed: 5 days — SLA BREACHED"]
    T2["Task Instance B\nRef: وارد-2026-00419\nCurrently at Stage 2\nElapsed: 1 day — Green"]
    T3["Task Instance C\nRef: وارد-2026-00431\nCurrently at Stage 1\nElapsed: 2 hours — Green"]

    BP --> BS1
    BP --> BS2
    BP --> BS3
    BP --> BS4

    BP -.->|"instance"| T1
    BP -.->|"instance"| T2
    BP -.->|"instance"| T3

    class BP bp
    class BS1,BS2,BS3,BS4 stage
    class T2,T3 task
    class T1 breach
```

---

## Diagram 5 — Stage/Sub-stage Assignment Resolution

How the system determines who is assigned to a stage or sub-stage at runtime —
the key mechanism that makes org changes automatically reflected.

```mermaid
flowchart TD
    classDef q fill:#5D6D7E,color:#fff,stroke:#4A5568
    classDef yes fill:#1A7F5A,color:#fff,stroke:#116644
    classDef no fill:#922B21,color:#fff,stroke:#7B241C
    classDef result fill:#C8992A,color:#1B2A4A,stroke:#A07820,stroke-width:2px

    A["Task enters Stage/Sub-stage N"]
    B["Read assignment spec from Blueprint snapshot\ne.g. 'Director of Legal Department' or 'Legal Review Team'"]
    C{"Active delegation\nexists for this position?"}
    D{"Position currently\nfilled by a user?"}
    E["Assign to delegate(s)\nfor delegation period"]
    F["Assign to current\nposition occupant(s)"]
    G["Alert admin:\nPosition is vacant,\nmanual assignment required"]

    A --> B
    B --> C
    C -->|"Yes"| E
    C -->|"No"| D
    D -->|"Yes"| F
    D -->|"No"| G

    class A,B q
    class E,F result
    class G no
    class C,D q
```

---

## Feature-to-Module Mapping

| Feature Domain (from Feature Inventory v3) | Module |
| --- | --- |
| Organization & Structure Management | Organization |
| Working Calendar & Public Holiday Configuration | Organization |
| User & Profile Management | IAM |
| Delegation & Out-of-Office | IAM |
| Blueprint Management — Definition and Library | Blueprint |
| Blueprint Management — Stage and Sub-stage Definitions | Blueprint |
| Blueprint Management — Transition Rules | Blueprint |
| Blueprint Management — Versioning (V2) | Blueprint |
| Task Management — Creation and Lifecycle | Task |
| Task Management — External Reference Linking | Task |
| Stage Lifecycle Management | Task |
| Comments & Collaboration | Task |
| Recurring Tasks (V2) | Task |
| Follow-Up & Tracking | Tracking & SLA |
| SLA Engine — Stage/Sub-stage Timers | Tracking & SLA |
| SLA Engine — Holiday-Aware Calculation | Tracking & SLA |
| Escalation Engine | Tracking & SLA |
| Notifications & Alerts | Notification |
| Analytics & Dashboards | Analytics |
| Personal Workspace (cross-module views) | Analytics |
| User Onboarding & Training (Domain 21) | Onboarding |
| Document & Attachment Management | Document |
| Archive & Records Management | Audit + Document |
| Audit Trail | Audit |
| Search & Discovery | Search |
| Documentation / Help Center | Help Center |
| Committee Management (V2) | Committee |
| Multi-Language & Localization | Core |
| System Administration | Core + all modules |

---

## Module Boundary Rules

**Rule 1 — No direct database joins across module boundaries.**
Each module queries only its own tables. Cross-module data is accessed through
internal service method calls or read models derived from events. No module
imports another module's ORM models.

**Rule 2 — Blueprint governs Task; Task does not modify Blueprint.**
The Blueprint module provides the lifecycle definition. When a task is created, the Task
module stores a snapshot of the relevant Blueprint version. Even if the Blueprint is later
updated or versioned, the task follows the rules of its snapshot. A task never writes back
to Blueprint tables.

**Rule 3 — Stage/sub-stage assignment is resolved at runtime through service calls, never cached statically.**
When a task enters a stage or sub-stage, the Task module calls the Organization and IAM modules to
resolve the current person or people matching the assignment specification. If a delegation is active,
IAM provides the delegate. If a required position is vacant, the system raises an alert.
This means org structure changes and delegations are reflected immediately in new stages and sub-stages.

**Rule 4 — Tracking & SLA monitors; it does not own task data.**
The SLA engine owns only SLA timer records and escalation records. It observes stage
and sub-stage entry and exit events emitted by the Task module. It fires breach and warning events
to the Notification module. It never writes to task tables.

**Rule 5 — Analytics is always read-only.**
Analytics never writes to any domain table. It reads from dedicated read models or query
views. The entire Analytics module can be rebuilt from scratch without touching any
business or operational data.

**Rule 6 — Audit receives events; it never queries back.**
All modules emit domain events to the Audit module. Audit stores them immutably and
acknowledges receipt. No other module reads from Audit tables at runtime. Audit data
is accessed only by humans performing compliance reviews, through the admin UI.

**Rule 7 — IAM is consulted, not embedded.**
No module duplicates permission logic. Every access check calls the IAM module's ABAC
policy engine. Permission rules live in exactly one place.

**Rule 8 — Core owns nothing domain-specific.**
Core provides tenant resolution, event bus infrastructure, base model traits
(soft delete, timestamps, audit hooks), dual-date utilities, and localization helpers.
It has no knowledge of what a Blueprint, Task, or Stage is. No business logic lives in Core.

**Rule 9 — Onboarding reads access profile; it does not assign permissions.**
The Onboarding module calls IAM to determine the user's account type, current position, authority grade, capabilities, monitoring scopes, and task-participation pattern. It selects the correct journey based on that access profile. It never writes to IAM tables, modifies positions, or grants capabilities. Journey progress and quiz scores are stored in Onboarding's own tables only.

**Rule 10 — Help Center is self-contained content management.**
The Help Center module manages its own articles, categories, and content. It calls IAM for access decisions (who can create, edit, and delete articles). It emits article lifecycle events to Audit. Article content is indexed by the Search module. It does not read from or write to any business module (Blueprint, Task, Tracking). It has no knowledge of tasks, stages, or workflows.

**Rule 11 — Platform Administration is external to tenants.**
The Platform module interacts only with the Central Management Database. It provisions tenants, manages subscriptions, and logs impersonation initiations. It never queries tenant databases directly except via an active, authenticated impersonation session context.

---

## Shared Concerns (Cross-Cutting, Not Module-Owned)

| Concern | How Handled |
| --- | --- |
| **Multi-tenancy** | Core resolves tenant context from every request. All module queries inherit tenant scope transparently. No module manages tenant isolation itself. |
| **Localization (Arabic/English)** | Core provides locale helpers and RTL/LTR context. All modules use them for labels, dates, notifications, and document generation. |
| **Hijri Calendar** | Core provides dual-date conversion utilities. Organization provides working-day and holiday rules. Tracking & SLA uses both for every deadline calculation. |
| **Policy-Based ABAC Permissions** | IAM owns the policy engine, capability grants, scoped grants, and policy rules store. All modules call IAM for access decisions. No module embeds its own permission checks. |
| **Soft Deletes** | Applied at base model level in Core. Every module inherits it. Nothing in any business or operational table is hard-deleted. |
| **Audit Logging** | An event listener in the Audit module captures events emitted by all other modules. Modules emit events and do not log directly. |

---

## What is NOT in Any Module at This Stage

Explicitly deferred or out of scope for the entire current design phase:

- Correspondence document management — external government system, not this platform
- G2G (Government-to-Government) secure messaging integration — V3
- Digital signature integration (UAE PASS, Nafath, Tasdeeq) — V3
- ERP / SAP / Oracle integration — V3
- Procurement workflow module — V3
- Security clearance and Need-to-Know classification domain — V3
- AI / predictive analytics engine — V3
- eID national identity provider integration — V3

---

*Document version: 1.0* 
*from Feature Inventory v1.2 (296 features, 23 domains)*
*Next: Visibility & Access Rules*
