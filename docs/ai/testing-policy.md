# Testing Policy — Momentum Backend

> Read when adding or changing behavior that needs verification.

---

## Philosophy

- Test **behavior**, not implementation
- Feature tests are **mandatory** for all API endpoints and critical flows
- Unit tests only for **complex business logic** (ABAC rules, SLA calculation, assignment resolution)

---

## Stack

| Type | Tool | When |
|------|------|------|
| Feature / API | Pest | Every endpoint, auth, ABAC, tenant isolation |
| Unit | Pest | Pure logic isolated from HTTP/DB where complexity warrants |

---

## Coverage Rules

- **Every new API endpoint:** at least happy path + one authorization failure + one validation failure
- **Tenant isolation:** prove tenant A cannot read tenant B data (separate DB connections in test)
- **ABAC:** prove capability denial and confidential task restrictions
- **Platform provisioning:** feature test with central DB + template tenant DB
- **No controller unit tests** — too thin; cover via feature tests

---

## Test Structure

All test files live under `tests/Feature/Modules/{ModuleName}/`:

```
tests/
├── Feature/
│   └── Modules/
│       ├── Blueprint/
│       │   ├── BlueprintTest.php
│       │   ├── BlueprintCategoryTest.php
│       │   ├── BlueprintStageTest.php
│       │   ├── BlueprintSubStageTest.php
│       │   ├── BlueprintTransitionTest.php
│       │   ├── SlaPolicyTest.php
│       │   └── StageTypeTest.php
│       ├── Iam/
│       │   ├── AuthenticationTest.php
│       │   ├── AuditGrantTest.php
│       │   ├── CapabilityTest.php
│       │   ├── DelegationTest.php
│       │   ├── IamPolicyTest.php
│       │   ├── MonitoringScopeGrantTest.php
│       │   ├── OutOfOfficeTest.php
│       │   ├── PositionAssignmentTest.php
│       │   ├── PositionCapabilityGrantTest.php
│       │   ├── UserCapabilityGrantTest.php
│       │   └── UserTest.php
│       ├── Organization/
│       │   ├── AuthorityGradeTest.php
│       │   ├── DepartmentTest.php
│       │   ├── PositionTest.php
│       │   ├── PublicHolidayTest.php
│       │   └── WorkingCalendarTest.php
│       ├── Platform/
│       │   ├── PlatformAdminTest.php
│       │   ├── PlatformAuditEventTest.php
│       │   ├── PlatformAuthTest.php
│       │   └── PlatformUnauthenticatedTest.php
│       └── Task/
│           ├── AssignmentResolutionTest.php
│           ├── TaskLifecycleTest.php
│           ├── TaskPriorityTest.php
│           └── TaskTest.php
```

---

## Test Data

- Use factories — no hardcoded magic IDs in assertions
- `RefreshDatabase` per test class
- Use `public_id` in test HTTP calls, not internal ids

---

## Response Wrapping — Critical

`JsonResource::withoutWrapping()` is called globally in `AppServiceProvider`.

This means:

| Response Type | JSON Shape | Assertion Pattern |
|---|---|---|
| Single resource: `new Resource($model)` | `{"field": "value"}` | `assertJsonPath('field', 'value')` |
| Collection: `Resource::collection($items)` | `[{...}, {...}]` | `assertJsonStructure(['*' => ['field']])` |
| Cursor paginated: `->cursorPaginate()` | `{"data": [...], "next_cursor": "...", "has_more": true}` | `assertJsonStructure(['data' => [...], 'next_cursor', 'has_more'])` |

**Never** prefix with `data.` in `assertJsonPath` — it only appears in cursor-paginated responses.

---

## Two Distinct Test Setup Patterns

### Pattern A — Tenant Module Tests (Blueprint, IAM, Organization, Task)

```php
use App\Models\User;
use App\Services\Platform\TenantProvisioningService;
use Database\Seeders\CapabilitySeeder;
use Database\Seeders\TenantDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 1. Provision + initialize tenant
    $this->provisioner = app(TenantProvisioningService::class);
    $this->tenant = $this->provisioner->provision([
        'name_en' => 'Test Name',
        'name_ar' => 'اختبار',
        'slug' => 'prefix-'.uniqid(),
    ]);
    tenancy()->initialize($this->tenant);

    // 2. Seed
    $this->seed(CapabilitySeeder::class);
    $this->seed(TenantDatabaseSeeder::class);

    // 3. Create user with known password
    $this->user = User::factory()->tenantAdmin()->create(['password' => bcrypt('password')]);

    // 4. Login via API → get Bearer token
    $loginResponse = $this->withHeaders(['X-Tenant' => $this->tenant->public_id])
        ->postJson('/v1/iam/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);
    $this->token = $loginResponse->json('token');
    $this->authHeaders = [
        'Authorization' => "Bearer {$this->token}",
        'X-Tenant' => $this->tenant->public_id,
    ];

    // 5. (Optional) Create domain entities needed by tests
    $this->category = BlueprintCategory::factory()->create();
    $this->blueprint = Blueprint::factory()->create([...]);
});

afterEach(function () {
    tenancy()->end();
    cleanupTenantDatabase($this->tenant->database_name);
    $this->tenant->delete();
});
```

**Every HTTP request** includes `$this->authHeaders`:
```php
$response = $this->withHeaders($this->authHeaders)->postJson('/v1/tasks', [...]);
```

### Pattern B — Platform / Central DB Tests (no tenant)

```php
use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 1. Create platform admin user directly (no tenant provisioning)
    $this->admin = User::factory()->create([
        'account_type' => AccountType::PLATFORM_ADMIN,
        'is_active' => true,
        'password' => bcrypt('password'),
    ]);

    // 2. Login via platform auth endpoint
    $loginResponse = $this->postJson('/v1/platform/auth/login', [
        'email' => $this->admin->email,
        'password' => 'password',
    ]);
    $this->token = $loginResponse->json('token');
});

// No afterNeeded — RefreshDatabase handles central DB cleanup
// No X-Tenant header on any request
```

---

## Auth Patterns

| Pattern | Login Endpoint | Headers | Used By |
|---------|---------------|---------|---------|
| API login + Bearer | `POST /v1/iam/auth/login` | `Authorization: Bearer {token}` + `X-Tenant: {public_id}` | Blueprint, IAM, Task |
| `actingAs` + X-Tenant | None (session) | `X-Tenant: {public_id}` | `TaskPriorityTest` only |
| Platform login + Bearer | `POST /v1/platform/auth/login` | `Authorization: Bearer {token}` only | Platform tests |

---

## Cleanup

**Tenant tests** call in `afterEach`:
```php
tenancy()->end();
cleanupTenantDatabase($this->tenant->database_name);
$this->tenant->delete();
```

Optional model force-delete before tenancy end if soft-delete models accumulate:
```php
SomeModel::whereNotNull('id')->forceDelete();
```

**Platform tests** need no `afterEach` — `RefreshDatabase` handles the central DB.

**Global safety net** in `tests/Pest.php`:
```php
function cleanupTenantDatabase(?string $databaseName): void { /* deletes SQLite file */ }
cleanupAllTenantDatabases();  // called at file load
```

---

## Common Assertion Patterns

```php
// Single resource
$response->assertOk()
    ->assertJsonPath('name_ar', 'اسم عربي');

// List (non-paginated, withoutWrapping)
$response->assertOk()
    ->assertJsonStructure(['*' => ['public_id', 'name_ar']])
    ->assertJsonCount(3);

// Cursor-paginated list
$response->assertOk()
    ->assertJsonStructure(['data' => [['public_id', 'name_ar']], 'next_cursor', 'has_more']);

// Validation error
$response->assertStatus(422)
    ->assertJsonValidationErrors('name_ar');

// Authorization denial
$response->assertForbidden();

// Unauthenticated
$response->assertStatus(401);

// Deleted
$response->assertStatus(204);

// Database check
$this->assertDatabaseHas('tasks', ['title_ar' => 'مهمة جديدة']);

// Pest inline assertions
expect($task->fresh()->status)->toBe(TaskStatus::Active);
expect($isActive)->toBeFalse();
```

---

## Running Tests

```bash
# Full suite
php artisan test

# Single module
php artisan test --filter="Modules\\Blueprint"
php artisan test --filter="Modules\\Platform"
php artisan test --filter="Modules\\Task"

# Single test file
php artisan test tests/Feature/Modules/Task/TaskTest.php

# Single test method
php artisan test --filter="it creates a blueprint with organization scope"
```

---

## CI Rules

- All tests pass before merge to `main`
- CI runs Pest on every PR and before VPS deploy
- Failing tests block deployment

---

→ **Next:** [release-policy.md](release-policy.md)
