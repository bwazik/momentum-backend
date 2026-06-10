# Coding Standards — Momentum Backend

> Read when writing new code, refactoring, or unsure about structure.
> **MUST read before writing ANY implementation code.**

---

## General Principles

- Explicit over implicit; one class, one responsibility
- Smallest change that satisfies the spec
- Match patterns in the active module before inventing new ones
- Module boundary rules in `architecture.md` are non-negotiable

---

## Naming Conventions

| Thing | Convention | Example |
|-------|-----------|---------|
| Classes | PascalCase | `TenantProvisioner` |
| Methods | camelCase | `provisionTenant()` |
| Variables | camelCase | `$tenantDatabase` |
| DB columns | snake_case | `public_id`, `created_at` |
| Routes | kebab-case | `/api/v1/platform/tenants` |
| Capabilities | dot notation | `task.view.organization` |
| Enum storage | TINYINT + PHP enum class | `TaskStatus::Active` |

---

## Architecture Patterns

### Controllers
- Thin: validate → delegate to service → return API Resource
- Versioned under `routes/api/v1/`
- No business logic

### Services (per module)
- All business logic lives in `app/Modules/{Module}/Services/`
- Injected via constructor
- Emit domain events for Audit / Tracking / Notification consumers
- **Must wrap multi-write operations in `DB::transaction()`** (see Database Transactions below)
- **Must use try/catch with module-specific logging** (see Error Handling & Logging below)

### Models
- Relationships, casts, scopes, soft deletes only
- Tenant DB models: **no** `tenant_id` attribute
- Route model binding resolves by `public_id`
- Follow the conventions in `app/Models/User.php` exactly: PHP 8 attributes (`#[Fillable]`, `#[Hidden]`), `casts()` method, `HasFactory` typing — use existing models as the reference for patterns.

### API Resources
- **Required** for every response
- Expose `public_id`, never internal `id`
- Transform column names to frontend-friendly keys where helpful

### Migrations
- `database/central/` for central DB
- `database/tenant/` for tenant template (applied to each tenant DB)

---

## Module Communication

- **Allowed:** Service method calls, domain events, queue jobs
- **Forbidden:** Importing another module's Eloquent models for queries

---

## Pagination Strategy

### Default: Cursor Pagination

All list endpoints returning potentially large datasets **must** use Laravel's `cursorPaginate()`:

```php
// ✅ Correct — cursor pagination
return DepartmentResource::collection(
    Department::query()
        ->with('parent')
        ->orderBy('id')
        ->cursorPaginate($request->integer('per_page', 15))
);

// ❌ Wrong — offset pagination on large tables
return DepartmentResource::collection(
    Department::query()->paginate(15)
);
```

**Why:** `OFFSET 500000` scans and discards rows — O(n). Cursor pagination does an index seek on `id` — O(1) regardless of position. At 1M+ records this is the difference between 2ms and 2000ms.

**API contract for cursor pagination:**

```json
{
  "data": [...],
  "next_cursor": "eyJpZCI6MTAwfQ==",
  "has_more": true
}
```

Frontend passes `?cursor=<next_cursor>` to fetch the next page. No `current_page`/`total` in cursor-paginated responses.

### Exception: Small Stable Tables

Tables with < 100 rows that rarely grow (capabilities, authority grades, account types) should return the **full list without pagination**:

```php
return AuthorityGradeResource::collection(AuthorityGrade::orderBy('rank')->get());
```

### Ordering Requirement for Cursor Pagination

Cursor pagination **requires** an `orderBy('id')` clause. The `id` column must have an index (it does by default as primary key). If sorting by another column, add a composite index.

---

## Performance & Query Optimization

### Eager Loading (N+1 Prevention)

Every API Resource that accesses a relationship **must** eager-load it. Verify by checking `with()` in the query:

```php
// ✅ Correct — eager load relationships used by the resource
User::with('currentPositionAssignment.position.department')->cursorPaginate(15);

// ❌ Wrong — N+1 in resource when accessing $user->position->department
User::cursorPaginate(15);
```

In tests, temporarily add `DB::listen()` to catch N+1 during development:

```php
DB::listen(function ($query) {
    if ($query->time > 100) {
        logger()->warning('Slow query', ['sql' => $query->sql, 'time' => $query->time]);
    }
});
```

### Index Strategy

| Index Type | When to Use | Example |
|------------|-------------|---------|
| B-tree (default) | Equality, range, ORDER BY | `index('is_active')` |
| Composite | Multi-column WHERE + ORDER | `index(['department_id', 'is_active'])` on positions |
| Partial (conditional) | Soft-delete + status queries | `MathableIndex` or raw: `WHERE deleted_at IS NULL` |
| Unique | Business uniqueness | `unique(['user_id', 'capability_id'])` with `whereNull('revoked_at')` |

**Partial indexes for soft-deleted models:**

```php
// In migration — index only active (non-deleted) rows
$table->index(['email'], 'users_email_active_index');
// Raw: CREATE INDEX users_email_active_index ON users (email) WHERE deleted_at IS NULL;
```

### Chunk for Bulk Operations

Never load millions of rows into memory. Use `chunk()` or `chunkById()`:

```php
User::where('is_active', true)
    ->chunkById(500, function (Collection $users) {
        foreach ($users as $user) {
            // Process each user
        }
    });
```

---

## Caching (Redis / phpredis)

### Architecture

Redis is the cache driver configured via `phpredis` extension. All cache keys are **tenant-prefixed** using the tenant slug to guarantee isolation.

### Key Naming Convention

```
{tenant_slug}:{module}:{entity}:{identifier}
```

Examples:
```
moj:iam:capabilities:all
moj:iam:policy:{user_public_id}
moj:organization:department_tree:full
moj:organization:authority_grades:all
```

### TTL Tiers

| Tier | TTL | Use Case | Example |
|------|-----|----------|---------|
| Hot | 60 seconds | Per-user policy results, volatile data | `iam:policy:{user_id}` |
| Warm | 300 seconds (5 min) | Reference data that changes rarely | `iam:capabilities:all`, `organization:authority_grades:all` |
| Cold | 3600 seconds (1 hour) | Static configuration | `organization:department_tree:full`, working calendars |

### Invalidation Strategy

Cache is **invalidated by domain events**, not by TTL expiry alone:

```php
// In a domain event listener
class InvalidateCapabilityCache
{
    public function handle(CapabilityGranted $event): void
    {
        $tenantSlug = tenant()->slug;
        Cache::tags(["{$tenantSlug}:iam:capabilities"])->flush();
        Cache::forget("{$tenantSlug}:iam:policy:{$event->user->public_id}");
    }
}
```

Use **cache tags** for grouped invalidation (all Redis drivers support tags via the `TaggableCacheStore`).

### What to Cache

| What | Where | TTL | Invalidation |
|------|------|-----|-------------|
| Capability catalog | `iam:capabilities:all` | 5 min | On any grant/revoke event |
| Department tree | `organization:department_tree:full` | 1 hour | On department create/update/deactivate |
| Authority grades | `organization:authority_grades:all` | 1 hour | On authority grade create/update |
| User effective capabilities | `iam:policy:{user_public_id}` | 60s | On any grant/revoke/delegation event |
| Working calendar holidays | `organization:holidays:{calendar_id}:{year}` | 1 hour | On holiday create/update |

### What NOT to Cache

- **Paginated list results** — cursor pagination is fast enough; stale pages cause confusion
- **User passwords or tokens** — never
- **Data that changes every request** — defeats the purpose

### Per-Request Memory Cache

For `IamPolicy::check()` calls within a single request, use an in-memory singleton cache. This avoids redundant Redis round-trips within the same HTTP request:

```php
// IamPolicy is registered as a singleton
// First check() builds the capability list from Redis/DB
// Subsequent check() calls hit the in-memory cache
// Cache is cleared via AppServiceProvider::registerTerminatingCallback()
```

---

## Rate Limiting

### Philosophy

Rate limiting is applied at the **controller level** via a reusable `HasRateLimiting` trait, not in route files. This keeps rate limit key composition flexible (any combination of email, IP, tenant, user ID, etc.) and avoids cluttering route definitions with middleware strings.

Three files work together:

| File | Purpose |
|------|---------|
| `app/Support/RateLimits.php` | All rate limit definitions — constants only, no logic |
| `app/Traits/HasRateLimiting.php` | Reusable trait with `checkRateLimit()` / `clearRateLimit()` / `remainingAttempts()` methods |
| `app/Exceptions/ThrottleException.php` | Consistent 429 JSON response with `Retry-After` header |

### 1. Configuration — `app/Support/RateLimits.php`

All limit names, attempt counts, and decay windows live in a single class. No magic numbers anywhere else.

```php
<?php

namespace App\Support;

final class RateLimits
{
    public const AUTH_LOGIN = 'auth-login';
    public const AUTH_LOGIN_ATTEMPTS = 5;
    public const AUTH_LOGIN_DECAY_MINUTES = 1;

    public const MUTATE = 'mutate';
    public const MUTATE_ATTEMPTS = 30;
    public const MUTATE_DECAY_MINUTES = 1;

    public const LIST = 'list';
    public const LIST_ATTEMPTS = 60;
    public const LIST_DECAY_MINUTES = 1;

    public const PASSWORD_RESET = 'password-reset';
    public const PASSWORD_RESET_ATTEMPTS = 3;
    public const PASSWORD_RESET_DECAY_MINUTES = 1;

    public static function attempts(string $name): int { /* ... */ }
    public static function decayMinutes(string $name): int { /* ... */ }
}
```

Add a new limiter by adding 3 constants (name, attempts, decay) — no other code changes needed.

### 2. Implementation — `app/Traits/HasRateLimiting.php`

A trait that controllers (or services) `use` to check rate limits with a single method call:

```php
trait HasRateLimiting
{
    protected function checkRateLimit(string $limiterName, string|array $keyParts): void
    {
        // Builds key: "auth-login:user@x.com|127.0.0.1"
        // Checks RateLimiter::tooManyAttempts()
        // Hits RateLimiter::hit()
        // Throws ThrottleException with consistent 429 response if exceeded
    }

    protected function clearRateLimit(string $limiterName, string|array $keyParts): void
    {
        // Clears the key — call on successful login to reset attempt counter
    }

    protected function remainingAttempts(string $limiterName, string|array $keyParts): int
    {
        // Returns remaining attempts without modifying the counter
    }
}
```

### 3. Exception — `app/Exceptions/ThrottleException.php`

```php
class ThrottleException extends Exception
{
    public function __construct(
        public readonly string $limiterName,
        public readonly int $retryAfterSeconds,
    ) { /* ... */ }

    public function render(): JsonResponse
    {
        // Returns {"message": "Too many requests. Please try again in 45 seconds."}
        // Status 429 with Retry-After header
    }
}
```

Register in `bootstrap/app.php`:

```php
$exceptions->renderable(fn (ThrottleException $e) => $e->render());
```

### 4. Usage in Controllers

```php
class AuthController extends Controller
{
    use HasRateLimiting;

    public function login(LoginRequest $request): AuthTokenResource
    {
        $this->checkRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);

        // ... authenticate ...

        $this->clearRateLimit(RateLimits::AUTH_LOGIN, [
            $request->input('email'),
            $request->ip(),
        ]);
    }
}
```

The `$keyParts` array supports any combination of values. Common building blocks:

| Key Part | When |
|----------|------|
| `$request->ip()` | Global limits (password reset) |
| `$request->input('email')` | Per-account limits |
| `$request->user()?->public_id` | Per-user limits |
| `tenant()?->slug` | Per-tenant limits |
| Any combination | Multiple dimensions |

### 5. Rate Limit Tiers

| Tier | Limit | Key | Use Case |
|------|-------|-----|----------|
| `auth-login` | 5/min | email + IP | Login endpoint |
| `mutate` | 30/min | user_id | POST/PUT/DELETE on resources |
| `list` | 60/min | user_id | GET paginated/cursor lists |
| `password-reset` | 3/min | IP | Password reset (future) |

### 6. What NOT To Do

- **No magic numbers** — always use `RateLimits::AUTH_LOGIN_ATTEMPTS`, never `5`
- **No Route middleware throttle** — use the `HasRateLimiting` trait in controllers, not `->middleware('throttle:...')` in route files
- **No manual `RateLimiter::tooManyAttempts()` / `RateLimiter::hit()`** — use the trait methods for consistent keys and error handling

---

## Database Transactions

### Rule: Multi-Write Operations Must Use Transactions

Any service method that performs **2 or more write operations** (inserts, updates, deletes) must wrap them in `DB::transaction()`:

```php
use Illuminate\Support\Facades\DB;

public function grantToPosition(Position $position, array $data, User $grantedBy): PositionCapabilityGrant
{
    return DB::transaction(function () use ($position, $data, $grantedBy) {
        // Write 1: Check duplicate
        $exists = PositionCapabilityGrant::where('position_id', $position->id)
            ->where('capability_id', $capability->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($exists) {
            throw new DuplicateGrantException('position capability grant');
        }

        // Write 2: Create grant
        $grant = PositionCapabilityGrant::create([...]);

        // Event dispatches after commit (ShouldDispatchAfterCommit)
        event(new CapabilityGranted($grant, 'position'));

        return $grant;
    });
}
```

### Domain Events Must Use ShouldDispatchAfterCommit

All domain events implement `ShouldDispatchAfterCommit` to prevent events from firing before the DB transaction completes:

```php
class CapabilityGranted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
}
```

This ensures listeners only receive events for committed data, not rolled-back data.

### Single-Write Operations

Simple CRUD operations (single insert, single update) don't need a transaction — Laravel's Eloquent handles atomicity for single statements. But if the operation **validates then writes**, consider whether the validation query + write query should be atomic.

---

## Error Handling & Logging

### Per-Module Logging Channels

Each module gets its own logging channel, writing to a dedicated log file. Configure in `config/logging.php`:

```php
// config/logging.php — add per-module channels
'iam' => [
    'driver' => 'daily',
    'path' => storage_path('logs/iam.log'),
    'level' => 'debug',
    'days' => 14,
],
'organization' => [
    'driver' => 'daily',
    'path' => storage_path('logs/organization.log'),
    'level' => 'debug',
    'days' => 14,
],
'platform' => [
    'driver' => 'daily',
    'path' => storage_path('logs/platform.log'),
    'level' => 'debug',
    'days' => 14,
],
'blueprint' => [
    'driver' => 'daily',
    'path' => storage_path('logs/blueprint.log'),
    'level' => 'debug',
    'days' => 14,
],
'task' => [
    'driver' => 'daily',
    'path' => storage_path('logs/task.log'),
    'level' => 'debug',
    'days' => 14,
],
'tracking' => [
    'driver' => 'daily',
    'path' => storage_path('logs/tracking.log'),
    'level' => 'debug',
    'days' => 14,
],
'notification' => [
    'driver' => 'daily',
    'path' => storage_path('logs/notification.log'),
    'level' => 'debug',
    'days' => 14,
],
'audit' => [
    'driver' => 'daily',
    'path' => storage_path('logs/audit.log'),
    'level' => 'debug',
    'days' => 30,
],
```

### Try/Catch in Services — Required Pattern

Every service method that can fail **must** wrap its logic in try/catch, log to the module channel, and re-throw:

```php
use Illuminate\Support\Facades\Log;

class GrantService
{
    public function grantToPosition(Position $position, array $data, User $grantedBy): PositionCapabilityGrant
    {
        try {
            return DB::transaction(function () use ($position, $data, $grantedBy) {
                // ... business logic ...
            });
        } catch (DuplicateGrantException $e) {
            // Domain exceptions: log and re-throw (controller handles response)
            Log::channel('iam')->warning('Duplicate grant attempt', [
                'position_id' => $position->public_id,
                'capability_id' => $data['capability_id'] ?? null,
                'granted_by' => $grantedBy->public_id,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            // Unexpected errors: log with context and re-throw
            Log::channel('iam')->error('Failed to grant capability to position', [
                'position_id' => $position->public_id,
                'capability_id' => $data['capability_id'] ?? null,
                'granted_by' => $grantedBy->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### Structured Logging Context

Every log entry must include structured context:

```php
Log::channel('iam')->info('User created', [
    'tenant_slug' => tenant()->slug,
    'user_id' => $user->public_id,
    'action' => 'user.create',
    'entity_type' => 'user',
    'entity_id' => $user->public_id,
    'performed_by' => $authUser->public_id,
]);
```

**Required context keys:**
- `tenant_slug` — always
- `action` — verb.noun format (e.g., `user.create`, `grant.revoke`, `delegation.create`)
- `entity_type` — what entity was affected
- `entity_id` — public_id of the entity
- `performed_by` — public_id of the acting user (or `system` for automated actions)

### Exception Handler

Domain exceptions (e.g., `DuplicateGrantException`, `CircularDepartmentReferenceException`) are registered in `bootstrap/app.php` with specific HTTP status codes. Unexpected exceptions return 500 with no stack trace in production.

---

## Enum Usage

### Rule: Always Use PHP Enum Classes in Code

Database stores TINYINT. Model casts handle conversion. **Business logic and form requests always reference the enum class — never raw integers.**

### Declaring Enums

```php
// app/Enums/ScopeType.php
enum ScopeType: int
{
    case Tenant = 1;
    case OwnDepartment = 2;
    case SpecificDepartment = 3;
    case DepartmentTree = 4;
    case OwnTasks = 5;
}
```

### Using Enums in Form Requests

```php
use App\Enums\ScopeType;
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'scope_type' => ['required', Rule::enum(ScopeType::class)],
        // ❌ Never: 'scope_type' => ['required', 'integer', 'in:1,2,3,4,5'],
    ];
}
```

### Using Enums in Services

```php
// ✅ Correct — use enum case
$grant->scope_type = ScopeType::SpecificDepartment;

// ❌ Wrong — magic number
$grant->scope_type = 3;
```

### Using Enums in Controllers

```php
// ✅ Correct — compare against enum
if ($user->account_type === AccountType::TenantAdmin) {
    // ...
}

// ❌ Wrong — magic number
if ($user->account_type === 2) {
    // ...
}
```

### Using Enums in Migrations

```php
// Migration stores TINYINT
$table->unsignedTinyInteger('scope_type')->default(1);
$table->unsignedTinyInteger('account_type')->default(1);

// Model casts to enum
protected function casts(): array
{
    return [
        'scope_type' => ScopeType::class,
        'account_type' => AccountType::class,
    ];
}
```

### Enum Naming

- Use **TitleCase** for enum keys: `OwnDepartment`, `DepartmentTree`, `TenantAdmin`
- Place cross-cutting enums in `app/Enums/` (e.g., `AccountType`, `ScopeType`)
- Place module-specific enums in `app/Modules/{Module}/Enums/` (e.g., `TaskStatus` in Task module)

---

## Queues & Jobs

### When to Dispatch to Queue

| Operation | Should Queue? | Why |
|-----------|--------------|-----|
| Sending email notification | **Yes** | External SMTP call; don't block HTTP |
| Sending in-app notification | **Yes** | DB write + potential broadcast |
| Cache warming after provisioning | **Yes** | Multiple cache writes |
| Report generation (future) | **Yes** | Heavy computation |
| Domain event dispatch | **No** | `ShouldDispatchAfterCommit` handles timing; listeners decide if they queue |
| Simple CRUD (create user, etc.) | **No** | Too fast to justify queue overhead |

### Job Conventions

```php
// app/Modules/Iam/Jobs/SendUserWelcomeEmail.php
class SendUserWelcomeEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = [30, 60, 120]; // exponential backoff

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        // Send email
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('iam')->error('Welcome email job failed', [
            'user_id' => $this->user->public_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
```

### Must Use ShouldDispatchAfterCommit for Domain Events

All domain events implement `ShouldDispatchAfterCommit`. This is **non-negotiable** — it prevents events from firing for uncommitted data.

### Queue Workers and Tenant Context

Queue jobs that operate on tenant data must include tenant context (`tenant_slug` or `tenant_id`) in the payload. The worker switches the DB connection before executing:

```php
class ProcessSomething implements ShouldQueue
{
    public function __construct(
        public string $tenantSlug,
        public string $entityPublicId,
    ) {}

    public function handle(): void
    {
        // Tenant context is set by stancl/tenancy queue middleware
        // The job runs in the correct tenant DB
    }
}
```

---

## API Response Envelope

### Success Responses

All API responses use a consistent envelope:

```json
// Single resource
{
    "data": { ... }
}

// Cursor-paginated collection
{
    "data": [...],
    "next_cursor": "eyJpZCI6MTAwfQ==",
    "has_more": true
}

// Non-paginated collection (small tables)
{
    "data": [...]
}
```

### Error Responses

```json
// Validation error (422)
{
    "message": "The name_ar field is required.",
    "errors": {
        "name_ar": ["The name_ar field is required."]
    }
}

// Domain error (422)
{
    "message": "A position capability grant already exists for this capability."
}

// Auth error (401)
{
    "message": "Unauthenticated."
}

// ABAC denial (403)
{
    "message": "This action requires the iam.manage_users capability."
}

// Not found (404)
{
    "message": "No query results for model [App\\Modules\\Iam\\Models\\User]."
}

// Rate limit (429)
{
    "message": "Too many requests. Please try again later."
}
```

No stack traces in production. Error messages in production must not leak internal IDs or architecture details.

---

## What To Avoid

- `tenant_id` on tenant DB tables
- Hardcoded role checks (`if ($user->role === 'admin')`)
- Raw model return from controllers
- `env()` outside config files
- Cross-module Eloquent relationships spanning module boundaries
- God services >300 lines — split by use case
- Magic numbers in business logic — always use enum classes
- Offset pagination on tables expected to exceed 1000 rows
- N+1 queries in API Resources — always eager-load
- Unwrapped multi-write operations — always use `DB::transaction()`
- Service methods without logging — always log failures with module channelcontext
- Dispatching domain events without `ShouldDispatchAfterCommit`

---

## Code Style

- **Formatter:** Laravel Pint (PSR-12)
- Run before commit: `./vendor/bin/pint`

---

## Dependencies

- No new Composer packages without team discussion
- Prefer Laravel-first solutions for MVP

---

→ **Next:** [security-policy.md](security-policy.md)