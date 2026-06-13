# Business Requirements Document & Software Requirements Specification (BRD/SRS)

## Configurable Task Lifecycle Management Platform

>**Project Name:** Gov TMS — Configurable Task Lifecycle Management Platform  
>
>**Document Version:** 1.0 (Final)  
>
>**Status:** Ready for Stakeholder Sign-Off  

---

## 1. Executive Summary & Vision

### 1.1 The Business Problem
Government ministries and large-scale enterprise entities across the GCC region suffer from fragmented, manual task tracking. Strategic directives and critical operational requests frequently encounter bottlenecks caused by long, multi-tiered bureaucratic approval chains, out-of-office executives, and siloed departments. Current tracking methods rely heavily on offline spreadsheets, phone calls (*متابعة*), and unstructured WhatsApp groups, leading to a profound lack of visibility and accountability.

### 1.2 The Solution
Gov TMS is a highly configurable, multi-tenant Task Lifecycle Management platform. It replaces manual follow-ups with rigid, systemic accountability. Instead of generic "to-do" lists, Gov TMS allows organizations to model their complex operational workflows as structured "Blueprints." Every resulting task is tracked at the atomic "Stage" level, ensuring that at any given moment, leadership knows exactly who holds accountability for a task and how much time remains on their Service Level Agreement (SLA).

### 1.3 Business Objectives
*   **Absolute Accountability:** Eliminate the "black box" of departmental task assignments. 
*   **Predictable Turnaround Times:** Enforce strict SLAs per stage to accelerate decision-making.
*   **Automated Escalation:** Systemically bypass bottlenecks by escalating overdue tasks to higher authorities.
*   **Executive Visibility:** Provide C-level leadership with real-time, cross-departmental dashboards identifying operational friction points.

---

## 2. Target Audience & User Personas

The platform is designed to serve distinct operational roles within a strict bureaucratic hierarchy:

*   **Minister / Top Executive (H.E.):** Requires high-level, real-time dashboards to ensure national strategic objectives are not delayed by administrative friction.
*   **Undersecretary / Director:** Manages departmental throughput. Needs workload visibility and bottleneck identification to allocate resources effectively.
*   **Follow-up Specialist (المشرف على المتابعة):** The "air traffic controller" who monitors all active tasks, flags at-risk items, and triggers manual escalations before SLAs breach.
*   **Action Employee:** The execution layer. Requires clear, step-by-step instructions, centralized document attachments, and an unambiguous understanding of their deadlines.
*   **Platform Operator (Super Admin):** The Gov TMS technical provider responsible for provisioning isolated tenant environments and ensuring platform stability.

---

## 3. Core Operating Model

The entire platform revolves around three foundational business concepts:

### 3.1 The Blueprint
A Blueprint is a reusable, organization-defined template that dictates how a specific type of work must be executed. It acts as the immutable law for a workflow. It defines the required stages, the order of execution, the SLA for each step, and the rules governing who must complete the work.

### 3.2 The Task
A Task is a single, active instance launched from a Blueprint. Upon creation, the Blueprint's rules are permanently locked for that specific task, ensuring that the rules cannot be altered mid-flight. A task carries its own priority, overall deadline, and external reference links (such as correspondence numbers).

### 3.3 The Stage & Sub-Stage
The Stage is the atomic unit of accountability. A stage represents a distinct phase of work within a task. When a task enters a stage, the system resolves the assignment to a specific individual or group (dynamically adjusting for active delegations or vacant positions). The SLA timer begins immediately. If a stage requires smaller internal steps, it utilizes Sub-Stages, which carry their own discrete assignees and SLAs.

---

## 4. Visibility & Governance Model

Because government workflows contain highly sensitive data, Gov TMS employs a hybrid **Attribute-Based (ABAC)** and **Capability-Based (CBAC)** access control model. 

### 4.1 Relationship-Based Visibility
A user cannot simply search for and view all tasks in the system. To view a task, a user must have a validated relationship to it:
*   They are currently assigned to an active stage/sub-stage.
*   They were assigned to a previously completed stage.
*   They are the direct superior (manager) of someone assigned to the task.
*   They hold a specific "Monitoring Scope" capability granting them oversight over a particular department or Blueprint type.

### 4.2 Confidentiality Tiers
Every task is governed by a confidentiality tier:
*   **Public:** Visible to any authenticated internal user.
*   **Internal:** Visible only to direct participants and their management chains.
*   **Confidential:** Strictly restricted. Visible *only* to explicitly named participants. Standard monitoring capabilities and management chain visibility are completely revoked unless explicitly overridden.

*(For detailed policy logic, refer to technical document: `04_Visibility_Access_Rules.md`)*

---

## 5. System Architecture & Operating Environment

### 5.1 Multi-Tenancy Strategy (Physical Isolation)
Gov TMS utilizes a strict **Database-per-Tenant** architecture. Each government ministry or agency (Tenant) receives a physically isolated database. This guarantees that cross-tenant data spillage is technically impossible. A central Platform Database governs tenant provisioning and routing.

### 5.2 Technology Stack
*   **Frontend:** A modern, highly responsive Single Page Application (SPA) built with Next.js (React).
*   **Backend:** A robust RESTful API built on Laravel (PHP), operating on a decoupled architecture.
*   **Infrastructure:** The MVP is designed to run efficiently on a single, well-provisioned VPS utilizing Nginx, PostgreSQL, and Redis, avoiding unnecessary enterprise infrastructure bloat while maintaining strict multi-tenant routing via HTTP headers (e.g., `X-Tenant`).

*(For technical implementation details, refer to `07_Multi_Tenancy_Strategy.md`, `08_Architecture_Diagrams.md`, and `09_API_Frontend_Architecture.md`)*

---

## 6. Functional Capabilities (Domain Overview)

The platform delivers 23 distinct functional domains. The core capabilities are grouped as follows:

### 6.1 Organizational & User Management
*   Complete organizational hierarchy modeling (Sectors $\rightarrow$ Directorates $\rightarrow$ Sections).
*   Position-based assignment, divorcing authority from the individual to survive employee turnover.
*   Delegation and out-of-office routing to automatically bypass absent personnel.

### 6.2 Workflow Execution & Tracking
*   Creation and progression of tasks through strictly enforced stages.
*   Automated resolution of assignees based on organizational roles rather than static names.
*   A unified Follow-up Board allowing specialists to filter active tasks by bottleneck, SLA health, and priority.

### 6.3 Escalation & Notification
*   Configurable SLA warning and breach thresholds per stage.
*   Automated hierarchical escalation when an SLA is breached.
*   Multi-channel notifications (In-App and Email for MVP) to ensure rapid response.

### 6.4 Reporting & Analytics
*   Real-time executive dashboards highlighting systemic bottlenecks (e.g., "Which department is causing the most delays?").
*   Task aging reports and individual workload balancing views.

### 6.5 Platform Administration
*   Centralized capabilities for the Gov TMS operator to provision new tenants, manage subscriptions, and initiate fully audited impersonation sessions for technical support.

*(For the exhaustive list of all 296 features, refer to `02_Feature_Inventory.md`)*

---

## 7. Cross-Cutting Concepts

### 7.1 Immutable Audit Trail
Every system action—from advancing a stage to downloading a document—is permanently recorded in a tamper-proof audit log. The system tracks the exact user, timestamp, and action, ensuring absolute compliance with state audit bureau requirements.

### 7.2 Guided User Onboarding
To ensure high adoption rates among senior government staff, Gov TMS includes an in-app, access-profile-based onboarding engine. Upon first login, users receive an interactive walkthrough tailored precisely to their position and capabilities, followed by mandatory knowledge checks.

### 7.3 Embedded Help Center
A self-service, bilingual knowledge base exists natively within the platform, allowing tenant administrators to author operational guides and platform documentation, reducing the burden on internal IT support.

### 7.4 Localization & Cultural Adaptation
The platform is natively bilingual (Arabic RTL and English LTR). It deeply integrates the Hijri calendar alongside the Gregorian calendar for seamless date entry, search, and SLA calculation, ensuring compliance with GCC regulatory standards.

---

## 8. Assumptions, Constraints & Scope Exclusions

To ensure a successful and timely MVP delivery, the following boundaries have been strictly defined:

### 8.1 Assumptions
*   Tenants will map their own workflows into Blueprints during the onboarding phase.
*   The platform assumes all users have modern web browsers and stable internet connectivity.

### 8.2 MVP Constraints
*   **API Integrations:** No third-party API consumers or mobile apps are supported in the MVP. The API exists strictly to serve the internal Next.js frontend.
*   **Infrastructure:** The system will be deployed on a monolithic VPS infrastructure. Zero-downtime rolling deployments and Kubernetes clustering are deferred.

### 8.3 Strict Exclusions (Out of Scope for MVP)
*   **Correspondence Management:** Gov TMS is *not* a Document Management System (DMS) or a formal Correspondence Registry (الصادر والوارد). It links to external correspondence via reference numbers but does not replace the archiving system.
*   **G2G Integrations:** Integration with unified government data highways (e.g., Saudi GSB, UAE FEDNet) is deferred.
*   **Digital Signatures:** Integration with national digital identity providers (e.g., Nafath, UAE PASS) for document signing is deferred.
*   **Cross-Tenant Data Sharing:** Complete isolation is enforced. Tenants cannot assign tasks or share documents with other tenants on the platform.

---

*Document version: 1.0*  