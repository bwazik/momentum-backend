# MVP Architecture Diagrams

## Configurable Task Lifecycle Management Platform

>**Version:** 1.0  
>
>**Infrastructure Model:** Simple VPS Deployment (Monolithic Application)

---

## 1. System Context Diagram

This diagram provides a high-level overview of how users interact with the Gov TMS MVP and the external services the platform relies on.

```mermaid
flowchart TB
    subgraph Users ["Users"]
        TU["Tenant User\n(e.g., mof.tms.app)"]
        PA["Platform Admin"]
    end

    SYS["Gov TMS MVP\n(Core Application)"]

    subgraph External Systems ["External Services"]
        SMTP["SMTP / Email Service"]
        S3["Object Storage\n(S3 / MinIO)"]
    end

    TU -- "HTTPS" --> SYS
    PA -- "HTTPS" --> SYS
    
    SYS -- "Sends Emails" --> SMTP
    SYS -- "Reads/Writes Files" --> S3
```

---

## 2. Infrastructure Container Diagram (Single VPS)

This diagram breaks down the internal components running on the single VPS. It reflects the exact MVP deployment: no Kubernetes, no auto-scaling groups, and no complex load balancers. Everything runs within a single server boundary, utilizing shared services for cost efficiency while maintaining strict database-level data isolation.

```mermaid
flowchart TB
    Client["Client Browser"]

    subgraph VPS ["Single VPS / Dedicated Server"]
        direction TB
        
        Nginx["Web Server\n(Nginx / Apache)\nHandles SSL & Domain Routing"]
        
        subgraph AppLayer ["Application Layer"]
            PHP["Laravel Application\n(PHP-FPM)"]
            Worker["Queue Worker\n(Supervisor processing jobs)"]
            Cron["Task Scheduler\n(System Cron)"]
        end
        
        subgraph DataLayer ["Data Layer (Same VPS)"]
            Redis["Redis Server\n(Cache, Queues & Sessions)"]
            
            subgraph PG ["PostgreSQL Server"]
                CentralDB[("Central DB\n(Tenant Registry)")]
                TenantDB1[("Tenant A DB\n(e.g., mof)")]
                TenantDB2[("Tenant B DB\n(e.g., moh)")]
            end
        end
    end

    S3["External Object Storage\n(S3 API / External Service)"]

    Client -- "HTTPS Request" --> Nginx
    Nginx -- "FastCGI" --> PHP
    
    %% Application Layer Connections
    PHP -- "1. Resolve Tenant" --> CentralDB
    PHP -- "2. Read/Write Data" --> TenantDB1
    PHP -- "2. Read/Write Data" --> TenantDB2
    
    %% Background Workers
    Worker -- "Pops queued jobs" --> Redis
    Worker -- "Processes jobs for" --> TenantDB1
    Worker -- "Processes jobs for" --> TenantDB2
    Cron -- "Triggers scheduled tasks" --> PHP
    
    %% Caching & Storage
    PHP -- "Manages Sessions & Cache" --> Redis
    PHP -- "Uploads/Downloads Attachments" --> S3
```

### Component Breakdown
*   **Web Server (Nginx):** Terminates SSL and forwards requests to the PHP-FPM application.
*   **Application (Laravel / PHP-FPM):** The core monolithic backend handling HTTP requests, business logic, and database routing.
*   **Queue Worker (Supervisor):** A background PHP CLI process managed by Supervisor that listens to Redis queues to process heavy tasks (like sending emails or checking SLAs) asynchronously.
*   **PostgreSQL:** A single database server instance hosting the central management database and all isolated tenant databases.
*   **Redis:** A single Redis instance securely shared across tenants via key prefixing (namespacing).

---

## 3. Multi-Tenancy Request Flow (Sequence)

This sequence diagram illustrates how the Laravel application implements the Database-per-Tenant strategy on a single server, dynamically switching connections without cross-tenant data spillage.

```mermaid
sequenceDiagram
    participant User
    participant Nginx as Web Server
    participant App as Laravel Application
    participant Redis as Redis (Session)
    participant CentralDB as Central Database
    participant TenantDB as Target Tenant Database

    User->>Nginx: GET mof.tms.app/dashboard
    Nginx->>App: Forward Request
    App->>App: Extract X-Tenant Header ("mof")
    App->>CentralDB: Query `tenants` table where slug = 'mof'
    CentralDB-->>App: Return Tenant Details (DB Name: tms_mof)
    App->>Redis: Check Session/Auth (Prefix: mof_session_*)
    Redis-->>App: Session Valid & User Authenticated
    App->>App: Purge default DB connection & Switch to 'tms_mof'
    App->>TenantDB: Execute standard queries (Tasks, Blueprints)
    TenantDB-->>App: Return Isolated Tenant Data
    App-->>Nginx: Render HTML Response
    Nginx-->>User: Display Dashboard
```

### Flow Highlights
1.  **Stateless Routing:** The application does not hardcode tenant connections. It resolves the tenant database dynamically on every single HTTP request based on the `X-Tenant` header.
2.  **Shared Session Check:** It verifies authentication in the shared Redis cache using a strict tenant prefix so that a session token for Tenant A is invalid on Tenant B.
3.  **Strict Isolation:** Once the connection switches to the tenant database, all Eloquent ORM queries automatically target that specific database. No `WHERE tenant_id = ?` filters are needed in application code, eliminating the risk of cross-tenant data leaks.

---

*Document version: 1.0*  
*Next: API & Frontend Architecture Strategy*