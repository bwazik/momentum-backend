# Multi-Tenancy Strategy Validation

## Configurable Task Lifecycle Management Platform

>**Version:** 1.0  
>
>**Status:** Approved  

---

## 1. Executive Summary

This document outlines the Multi-Tenancy Strategy for the Gov TMS platform. It bridges the gap between the database architecture and the application/infrastructure tiers. The platform enforces strict physical data isolation at the database layer while optimizing compute and infrastructure costs by sharing application-level resources securely.

---

## 2. Database & Data Isolation

### 2.1 Database-per-Tenant Model
The application uses a strict Database-per-Tenant architecture. A Central Management Database stores the registry of tenants (e.g., domain slugs, database names, global configuration), while every tenant receives their own dedicated relational database.
*   **Tenant Mapping:** The application dynamically switches database connections on each HTTP request or background job based on the resolved tenant context.
*   **Cross-Tenant Isolation:** There is zero cross-tenant data sharing. Features like cross-tenant task assignment, document sharing, or reporting are strictly out of scope for the MVP.

---

## 3. Routing & Identity

### 3.1 Domain Strategy
*   **Access Routing:** Tenants access the platform via tenant-specific domains/URLs. The API relies on the `X-Tenant` HTTP header to resolve the database.
*   **SSL/TLS:** SSL certificates are managed manually or at the infrastructure/load-balancer layer for the MVP. Automated provisioning (e.g., Let's Encrypt integration) is deferred.

### 3.2 Authentication & User Isolation
*   **Decentralized Login:** There is no centralized login portal. Users navigate directly to their tenant's URL to authenticate.
*   **Identity Segregation:** User identities are entirely isolated within the tenant database. The same email address can exist across multiple tenants, but each represents a distinct account with its own password, profile, and permissions.
*   **Single Sign-On (SSO):** MVP uses standard local authentication. However, the authentication service architecture must remain extensible to support per-tenant SSO integrations (SAML, OAuth2, Microsoft Entra ID) in future releases.

---

## 4. Shared Application Services

To reduce infrastructure complexity and cost for the MVP, the application utilizes shared services securely partitioned via application logic.

### 4.1 Cache & Session Management
*   **Shared Redis Infrastructure:** The platform runs a shared Redis cluster for all tenants.
*   **Namespacing:** Tenant isolation at the cache level is achieved through strict key prefixing (e.g., `tenant_mof:session:abc`). Dedicated Redis instances are not used for the MVP.
*   **Session Storage:** User sessions are stored in Redis (leveraging the namespace prefixing) to provide better performance and scalability compared to database-backed sessions.

### 4.2 Background Jobs & Queues
*   **Shared Worker Pool:** Background processing (e.g., SLA timers, notifications) uses a shared pool of queue workers.
*   **Tenant Context Injection:** Every job pushed to the queue must carry a `tenant_id` payload. When a worker picks up the job, the application automatically resolves and switches to the correct tenant database connection before execution.

### 4.3 Object Storage (Documents & Attachments)
*   **Shared Bucket:** A single, centralized object storage bucket (e.g., AWS S3, MinIO) is used for all tenants.
*   **Directory Partitioning:** Isolation is enforced through structured file paths using a tenant-specific prefix (e.g., `s3://govtms-bucket/tenant-mof/documents/...`).
*   **Access Control:** The application acts as a proxy, generating pre-signed URLs or streaming files directly to ensure users can only access files belonging to their active tenant context.

---

## 5. Deployment, Provisioning & Operations

### 5.1 Tenant Provisioning
*   **Template Duplication:** When a new tenant is onboarded, their environment is provisioned by duplicating a pre-existing "Template Database" rather than executing the entire schema migration chain from scratch.
*   **Template Maintenance:** The template database is updated automatically during every platform deployment to ensure it always matches the latest approved schema.

### 5.2 Schema Migrations
*   **Deployment Strategy:** Deploying schema changes across all tenant databases requires a scheduled maintenance window. Brief platform-wide downtime is acceptable for the MVP. Zero-downtime rolling migrations are deferred to a later maturity phase.

### 5.3 Backup & Disaster Recovery
*   **Uniform Policy:** All tenants inherit the same centralized backup and retention policy (e.g., daily automated backups with standard point-in-time recovery). Custom backup schedules and varied retention periods are not supported in the MVP.

---

## 6. Security & Support Operations

### 6.1 Platform Support Access
*   **No Backdoors:** There are no hidden backdoor credentials or global super-admin master passwords.
*   **Audited Impersonation:** Platform administrators access tenant environments exclusively through controlled "impersonation workflows". An admin selects a target user, and the application generates a temporary, fully traceable impersonation session. All actions taken during this session are explicitly logged in the `audit_events` table as performed by the impersonating admin.

---

*Document version: 1.0*  
*Next: System Architecture Diagrams*