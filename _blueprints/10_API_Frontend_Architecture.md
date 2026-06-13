# API & Frontend Architecture Strategy

## Configurable Task Lifecycle Management Platform

>**Version:** 1.0  
>
>**Stack:** Next.js (React/TypeScript), Laravel API, PostgreSQL, Redis  
>
>**UI Ecosystem:** shadcn/ui, Tailwind CSS  

---

## 1. Executive Summary

This document defines the technical contract and architectural patterns for the Gov TMS platform. As a decoupled application built by a single developer, the strategy optimizes for **development speed, type safety, and seamless integration** between the Next.js frontend and the Laravel backend, actively avoiding enterprise API bloat (like strict versioning or third-party API tokens) that are unnecessary for the MVP.

---

## 2. Infrastructure & Routing (Cross-Origin Architecture)

To avoid the massive headaches of CORS (Cross-Origin Resource Sharing) and complex cookie domain configurations in a multi-tenant application, the frontend and backend will be served from the **same origin** using Nginx routing on the VPS.

*   **Header Resolution:** A user accesses `mof.govtms.sa`. The frontend automatically attaches `X-Tenant: mof` to every request to the central API domain.
*   **Nginx Configuration:**
    *   Requests to `mof.govtms.sa/api/*` and `mof.govtms.sa/sanctum/*` are routed to the **Laravel PHP-FPM** backend.
    *   All other requests (`mof.govtms.sa/*`) are routed to the **Next.js** Node server.
*   **Why this matters:** Because the API and frontend live on separate domains, CORS and cross-origin Sanctum must be configured. Laravel reads the `X-Tenant` header attached by the frontend to resolve the tenant database connection.

---

## 3. Authentication Strategy (Laravel Sanctum)

*   **Mechanism:** SPA Cookie-Based Authentication.
*   **Why:** Instead of manually managing, storing, and rotating API tokens in Next.js `localStorage` (which is vulnerable to XSS), we use Laravel Sanctum's built-in SPA authentication. 
*   **Flow:**
    1.  Next.js makes a GET request to `/sanctum/csrf-cookie` to initialize CSRF protection.
    2.  Next.js POSTs credentials to `/api/login`.
    3.  Laravel authenticates the user against the isolated tenant database and issues a secure, HttpOnly session cookie.
    4.  All subsequent Next.js API requests automatically include this cookie.

---

## 4. Frontend Architecture (Next.js & React)

### 4.1 Framework & State
*   **App Router:** Utilize the Next.js App Router for modern nested layouts, server components (for initial fast data loads like dashboard stats), and client components (for interactive UI).
*   **Component Library:** **shadcn/ui** is the perfect choice. Since it copies the component source code directly into your repository, you have 100% control over the markup and Tailwind styling, allowing rapid iteration without fighting a third-party library's constraints.
*   **Data Fetching:** Use **TanStack Query (React Query)** or **SWR** for client-side API calls. This provides automatic caching, background refetching, and drastically simplifies loading/error states compared to standard `useEffect` fetching.

### 4.2 Handling Complex State (The Blueprint Builder)
*   The Blueprint Builder (defining stages, SLAs, assignments, and transitions) is the most interactive part of the platform.
*   **Strategy:** Treat the Blueprint Builder as a standalone, heavy client-side application. Use local React state (via `useReducer` or a lightweight library like `Zustand`) to manage the complex, nested JSON structure in memory as the user drags and drops stages.
*   **API Interaction:** Instead of hitting the API every time a stage is added, the user builds the Blueprint locally. Upon clicking "Save", the entire structured JSON payload is sent via a single `POST /api/blueprints` or `PUT` transaction to Laravel, which validates and maps it to the respective database tables.

---

## 5. Backend Architecture (Laravel API)

### 5.1 API Design Principles
*   **Versioned Endpoints:** All API endpoints must be versioned from day one (e.g., `/api/v1/`). Although the API initially serves only the Next.js frontend, establishing URL-based versioning immediately prevents painful routing refactors later and paves the way for future mobile apps or third-party integrations.
*   **JSON Responses:** All endpoints return standard JSON. Use Laravel's `API Resources` to format responses. This creates a translation layer so that internal database column names (like `sequence_order`) can be transformed into frontend-friendly names (like `stepIndex`) if desired, and sensitive data is stripped out.

### 5.2 Validation & Error Handling
*   **Laravel Form Requests:** Handle all validation on the backend using standard Laravel Form Request classes.
*   **Error Mapping:** When validation fails, Laravel automatically returns a `422 Unprocessable Entity` with a standardized JSON error object. Next.js will catch this 422 response and map the error messages directly into the `shadcn/ui` form validation states, providing seamless real-time feedback to the user.

### 5.3 API Documentation (Swagger/OpenAPI)
*   **Auto-Generation Strategy:** Avoid writing and maintaining massive OpenAPI YAML files by hand. As a solo developer, you should use an auto-generating package like **Scramble** (`dedoc/scramble`).
*   **How it Works:** Scramble analyzes your Laravel routes, Form Request validation rules, and API Resources to generate a fully compliant OpenAPI (Swagger) UI automatically on the fly. This requires zero manual PHP annotations, ensuring your documentation is always perfectly synced with your backend code with zero extra maintenance effort.

---

## 6. Multi-Tenancy & UI Theming

Because the MVP utilizes header-based routing (`X-Tenant`), the frontend must be aware of its tenant context.

*   **Next.js Middleware:** Use Next.js Middleware to inspect the request hostname, extract the tenant slug, and inject it into the application context.
*   **Dynamic Theming:** On initial load, Next.js can fetch a `/api/tenant/branding` endpoint (which returns the tenant's name, logo URL from S3, and primary color hex code). Next.js can then dynamically inject this hex code into Tailwind via CSS variables (`--primary: #xxxxxx`), instantly branding the UI for that specific government entity.

---

*Document version: 1.0*  
*Next: BRD / SRS (Software Requirements Specification)*