# Implementation Plan: 001 Platform Admin

> **Spec:** `specs/001-platform-admin/spec.md`
> **Status:** `approved`
> **Branch:** `feat/001-platform-admin` from `main`

---

## Open Questions Resolved

1. **Slug immutability:** Slug is used for database naming (`momentum_tenant_{slug}`) and cache key prefixing. Changing it would require a database rename. Slug is immutable after creation — rejected in update validation.
2. **Run-migrations async:** Dispatch as a queued job. Endpoint returns 202 Accepted. No poll endpoint for MVP — status checked via central `audit_events`.
3. **Impersonation token expiry:** Impersonation tokens expire after 1 hour. Platform admin's own token has no expiry (standard Sanctum plain text token).
4. **Platform admins in central `users` table:** Yes. Use the existing `users` table in the central database with `account_type = 4` (PlatformAdmin). Simpler and consistent with the existing `AccountType` enum. No separate auth guard needed.
5. **Email verification:** Auto-set `email_verified_at = now()` on creation. Platform admins are created by other platform admins, not self-registration.

---

## Technical Approach

Build the Platform module under `app/Modules/Platform/` following the same module pattern as Organization and IAM. All platform endpoints operate on the **central database** — no `X-Tenant` header, no tenant context switching. Platform admins authenticate against the central DB using Sanctum tokens with `account_type = 4` check. The existing `TenantProvisioningService` is refactored to include transactions, logging, and audit events. The existing `ImpersonationService` is replaced with a proper impersonation flow that issues a tenant-scoped Sanctum token and logs to central `audit_events`.

### Key Decisions

- **Central DB only:** Platform module models extend `CentralModel` (not `TenantModel`). Controllers live under `app/Modules/Platform/Controllers/`. Routes under `/api/v1/platform/` use `auth:sanctum` + `RequirePlatformAdmin` middleware (not `RequireCapability` and not tenant middleware).
- **New `RequirePlatformAdmin` middleware:** Checks `auth:sanctum` + `account_type === AccountType::PLATFORM_ADMIN` + `is_active === true`. Separate from `RequireCapability` which is tenant-scoped.
- **Central `users` table for platform admins:** The central `users` migration needs the same columns as the tenant `users` migration (`public_id`, `name_ar`, `name_en`, `mobile`, `employee_id`, `account_type`, `preferred_language`, `is_active`, `deleted_at`). Platform admins are created with `account_type = 4` in this table.
- **Impersonation flow:** Platform admin POSTs to `/api/v1/platform/tenants/{tenant}/impersonate` with `user_public_id`. The service switches to the tenant DB, finds that user, creates a Sanctum token with `impersonated-by:{admin_id}` ability, and logs the impersonation start to central `audit_events`. The frontend then uses this token against tenant API endpoints (with `X-Tenant` header). The `RequireCapability` middleware detects the `impersonated-by` ability and passes the impersonated user through.
- **Audit events table in central DB:** New `audit_events` table with `public_id`, `user_id`, `action`, `entity_type`, `entity_id`, `payload` (JSONB), `ip_address`, `user_agent`, `created_at`. Append-only — no UPDATE or DELETE operations allowed.
- **TenantProvisioningService refactor:** The provisioning itself is NOT wrapped in `DB::transaction()` because stancl/tenancy's `TenantCreated` event triggers async DB creation. The audit event is created after successful provisioning. Suspend, reactivate, and update ARE wrapped in transactions.
- **ImpersonationService replace:** Complete rewrite. Remove the session-based approach. Use Sanctum token-based impersonation.

---

## Affected Modules / Files

### New Files

```
app/
├── Enums/
│   └── AuditAction.php                                    (NEW — string-backed enum)
├── Http/Middleware/
│   └── RequirePlatformAdmin.php                           (NEW — auth + account_type=4 check)
├── Modules/Platform/
│   ├── Controllers/
│   │   ├── PlatformAuthController.php                     (NEW — login, logout, me)
│   │   ├── PlatformAdminController.php                    (NEW — CRUD for platform admins)
│   │   ├── PlatformTenantController.php                   (NEW — CRUD for tenants)
│   │   ├── PlatformAuditEventController.php               (NEW — list audit events)
│   │   └── PlatformImpersonationController.php            (NEW — start/leave impersonation)
│   ├── Services/
│   │   ├── PlatformAuthService.php                        (NEW — central DB auth logic)
│   │   ├── PlatformAdminService.php                       (NEW — platform admin CRUD)
│   │   ├── PlatformTenantService.php                      (NEW — tenant lifecycle + audit)
│   │   └── PlatformImpersonationService.php               (NEW — replaces old ImpersonationService)
│   ├── Models/
│   │   └── AuditEvent.php                                 (NEW — central DB, extends CentralModel)
│   ├── Requests/
│   │   ├── PlatformLoginRequest.php                       (NEW)
│   │   ├── StorePlatformAdminRequest.php                   (NEW)
│   │   ├── UpdatePlatformAdminRequest.php                  (NEW)
│   │   ├── StoreTenantRequest.php                          (NEW)
│   │   ├── UpdateTenantRequest.php                        (NEW)
│   │   ├── ImpersonateRequest.php                         (NEW)
│   │   └── RunTenantMigrationsRequest.php                  (NEW)
│   ├── Resources/
│   │   ├── PlatformAdminResource.php                      (NEW)
│   │   ├── PlatformAuthResource.php                       (NEW)
│   │   ├── PlatformTenantResource.php                    (NEW)
│   │   └── AuditEventResource.php                         (NEW)
│   ├── Events/
│   │   ├── TenantProvisioned.php                          (NEW)
│   │   ├── TenantSuspended.php                            (NEW)
│   │   ├── TenantReactivated.php                          (NEW)
│   │   ├── TenantUpdated.php                              (NEW)
│   │   ├── PlatformAdminCreated.php                       (NEW)
│   │   ├── PlatformAdminDeactivated.php                   (NEW)
│   │   ├── PlatformAdminReactivated.php                   (NEW)
│   │   ├── ImpersonationStarted.php                       (NEW)
│   │   └── ImpersonationEnded.php                         (NEW)
│   └── Exceptions/
│       ├── TenantAlreadySuspendedException.php            (NEW)
│       ├── TenantAlreadyActiveException.php               (NEW)
│       ├── CannotImpersonateSelfException.php             (NEW)
│       ├── CannotImpersonatePlatformAdminException.php    (NEW)
│       └── PlatformAdminCannotDeactivateSelfException.php (NEW)
├── Jobs/
│   └── RunTenantMigrationsJob.php                          (NEW — queued job)
database/migrations/
│   └── 2026_06_10_000001_create_central_audit_events_table.php        (NEW)
│   └── 2026_06_10_000002_add_platform_admin_columns_to_central_users_table.php  (NEW)
routes/
│   └── api/v1/platform.php                                (NEW — all /api/v1/platform/* routes)
```

### Modified Files

| File | Change |
|------|--------|
| `app/Services/Platform/TenantProvisioningService.php` | Add try/catch logging with `Log::channel('platform')` |
| `app/Services/Platform/ImpersonationService.php` | **DELETE** — replaced by `PlatformImpersonationService` |
| `app/Models/Tenant.php` | Add `getRouteKeyName()` returning `'public_id'`, update `Str::uuid()` to `Str::uuid7()` |
| `app/Models/User.php` | Add `isPlatformAdmin()` helper method |
| `bootstrap/app.php` | Register `RequirePlatformAdmin` middleware alias, register Platform exception handlers, add rate limiters |
| `routes/api.php` | Include `routes/api/v1/platform.php` |
| `config/logging.php` | Platform channel already exists from refactoring |

---

## Implementation Notes

### 1. AuditAction Enum — `app/Enums/AuditAction.php`

```php
<?php

namespace App\Enums;

enum AuditAction: string
{
    case TenantCreate = 'tenant.create';
    case TenantUpdate = 'tenant.update';
    case TenantSuspend = 'tenant.suspend';
    case TenantReactivate = 'tenant.reactivate';
    case TenantRunMigrations = 'tenant.run_migrations';
    case PlatformAdminCreate = 'platform_admin.create';
    case PlatformAdminUpdate = 'platform_admin.update';
    case PlatformAdminDeactivate = 'platform_admin.deactivate';
    case PlatformAdminReactivate = 'platform_admin.reactivate';
    case ImpersonationStart = 'impersonation.start';
    case ImpersonationEnd = 'impersonation.end';
    case PlatformLogin = 'platform_admin.login';
    case PlatformLogout = 'platform_admin.logout';
}
```

### 2. RequirePlatformAdmin Middleware — `app/Http/Middleware/RequirePlatformAdmin.php`

```php
<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->account_type !== AccountType::PlatformAdmin) {
            abort(403, 'This action requires platform administrator privileges.');
        }

        if (! $user->is_active || $user->deleted_at !== null) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
```

**Key difference from `RequireCapability`:** This middleware checks `account_type` directly — platform admins don't use ABAC. They have unrestricted access to platform endpoints.

### 3. Central Migrations

#### `2026_06_10_000001_create_cental_audit_events_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->jsonb('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('entity_type');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
```

#### `2026_06_10_000002_alter_central_users_for_platform_admin.php`

This migration checks which columns the default Laravel `users` table already has and adds the missing ones needed for platform admins (to match the tenant `users` schema):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'public_id')) {
                $table->uuid('public_id')->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'name_ar')) {
                $table->string('name_ar')->after('public_id');
            }
            if (! Schema::hasColumn('users', 'name_en')) {
                $table->string('name_en')->nullable()->after('name_ar');
            }
            if (! Schema::hasColumn('users', 'mobile')) {
                $table->string('mobile')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id')->nullable()->unique()->after('mobile');
            }
            if (! Schema::hasColumn('users', 'account_type')) {
                $table->unsignedTinyInteger('account_type')->default(1)->after('employee_id');
            }
            if (! Schema::hasColumn('users', 'preferred_language')) {
                $table->unsignedTinyInteger('preferred_language')->default(1)->after('account_type');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('preferred_language');
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->after('remember_token');
            }
        });
    }

    public function down(): void
    {
        // Do not drop columns in down() — too risky to remove columns
        // that may have been part of the original migration.
    }
};
```

### 4. AuditEvent Model — `app/Modules/Platform/Models/AuditEvent.php`

```php
<?php

namespace App\Modules\Platform\Models;

use App\Enums\AuditAction;
use App\Models\CentralModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'user_id', 'action', 'entity_type', 'entity_id', 'payload', 'ip_address', 'user_agent'])]
class AuditEvent extends CentralModel
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

**Design notes:**
- Extends `CentralModel` — always uses central DB connection
- `AuditAction` enum for the `action` column
- Append-only — no update or delete methods. No `SoftDeletes`.
- Route model binding by `public_id`

### 5. User Model Addition — `app/Models/User.php`

Add the `isPlatformAdmin()` method:

```php
public function isPlatformAdmin(): bool
{
    return $this->account_type === AccountType::PlatformAdmin;
}
```

### 6. Tenant Model Updates — `app/Models/Tenant.php`

```php
// Add to Tenant model:
public function getRouteKeyName(): string
{
    return 'public_id';
}
```

And in `booted()`, change:
```php
// From:
$tenant->public_id = Str::uuid()->toString();
// To:
$tenant->public_id = (string) Str::uuid7();
```

### 7. PlatformAuthService — `app/Modules/Platform/Services/PlatformAuthService.php`

```php
<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Modules\Platform\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformAuthService
{
    public function login(string $email, string $password, string $ip): array
    {
        try {
            $user = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                ->first();

            if (! $user || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => __('auth.failed'),
                ]);
            }

            if ($user->account_type !== AccountType::PlatformAdmin) {
                throw ValidationException::withMessages([
                    'email' => __('auth.platform_login_only'),
                ]);
            }

            if (! $user->is_active || $user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'email' => __('auth.inactive'),
                ]);
            }

            $token = $user->createToken('platform-admin')->plainTextToken;

            AuditEvent::create([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'action' => AuditAction::PlatformLogin->value,
                'entity_type' => 'platform_admin',
                'entity_id' => $user->public_id,
                'payload' => ['ip_address' => $ip],
                'ip_address' => $ip,
            ]);

            return ['user' => $user, 'token' => $token];
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin login failed', [
                'action' => 'platform_admin.login',
                'entity_type' => 'platform_admin',
                'email' => $email,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function logout(User $user, bool $allDevices = false, string $ip): void
    {
        if ($allDevices) {
            $user->tokens()->delete();
        } else {
            $user->currentAccessToken()?->delete();
        }

        AuditEvent::create([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'action' => AuditAction::PlatformLogout->value,
            'entity_type' => 'platform_admin',
            'entity_id' => $user->public_id,
            'payload' => ['all_devices' => $allDevices],
            'ip_address' => $ip,
        ]);
    }
}
```

**Two test cases:**
1. Login with valid platform admin credentials → returns token and user
2. Login with tenant admin (account_type=2) credentials → 422 "Platform login only"

### 8. PlatformTenantService — `app/Modules/Platform/Services/PlatformTenantService.php`

```php
<?php

namespace App\Modules\Platform\Services;

use App\Enums\AuditAction;
use App\Modules\Platform\Exceptions\TenantAlreadyActiveException;
use App\Modules\Platform\Exceptions\TenantAlreadySuspendedException;
use App\Modules\Platform\Models\AuditEvent;
use App\Models\Tenant;
use App\Services\Platform\TenantProvisioningService;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformTenantService
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
    ) {}

    public function provision(array $data, int $adminUserId, string $ip): Tenant
    {
        try {
            $tenant = $this->provisioningService->provision($data);

            AuditEvent::create([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $adminUserId,
                'action' => AuditAction::TenantCreate->value,
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'payload' => ['slug' => $tenant->slug, 'name' => $tenant->name_en],
                'ip_address' => $ip,
            ]);

            return $tenant;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant provisioning failed', [
                'action' => 'tenant.create',
                'entity_type' => 'tenant',
                'admin_user_id' => $adminUserId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function suspend(Tenant $tenant, int $adminUserId, string $ip): Tenant
    {
        if (! $tenant->is_active) {
            throw new TenantAlreadySuspendedException;
        }

        try {
            return DB::transaction(function () use ($tenant, $adminUserId, $ip) {
                $tenant->update(['is_active' => false]);

                AuditEvent::create([
                    'public_id' => (string) Str::uuid7(),
                    'user_id' => $adminUserId,
                    'action' => AuditAction::TenantSuspend->value,
                    'entity_type' => 'tenant',
                    'entity_id' => $tenant->public_id,
                    'payload' => ['slug' => $tenant->slug],
                    'ip_address' => $ip,
                ]);

                return $tenant->fresh();
            });
        } catch (TenantAlreadySuspendedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant suspension failed', [
                'action' => 'tenant.suspend',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(Tenant $tenant, int $adminUserId, string $ip): Tenant
    {
        if ($tenant->is_active) {
            throw new TenantAlreadyActiveException;
        }

        try {
            return DB::transaction(function () use ($tenant, $adminUserId, $ip) {
                $tenant->update(['is_active' => true]);

                AuditEvent::create([
                    'public_id' => (string) Str::uuid7(),
                    'user_id' => $adminUserId,
                    'action' => AuditAction::TenantReactivate->value,
                    'entity_type' => 'tenant',
                    'entity_id' => $tenant->public_id,
                    'payload' => ['slug' => $tenant->slug],
                    'ip_address' => $ip,
                ]);

                return $tenant->fresh();
            });
        } catch (TenantAlreadyActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant reactivation failed', [
                'action' => 'tenant.reactivate',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(Tenant $tenant, array $data, int $adminUserId, string $ip): Tenant
    {
        unset($data['slug'], $data['database_name']);

        try {
            $tenant->update($data);

            AuditEvent::create([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $adminUserId,
                'action' => AuditAction::TenantUpdate->value,
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'payload' => $data,
                'ip_address' => $ip,
            ]);

            return $tenant->fresh();
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Tenant update failed', [
                'action' => 'tenant.update',
                'entity_type' => 'tenant',
                'entity_id' => $tenant->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(?string $search = null, ?bool $isActiveOnly = null, int $perPage = 15): CursorPaginator
    {
        $query = Tenant::query()->orderBy('id');

        if ($isActiveOnly !== null) {
            $query->where('is_active', $isActiveOnly);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_ar', 'ilike', "%{$search}%")
                    ->orWhere('slug', 'ilike', "%{$search}%");
            });
        }

        return $query->cursorPaginate($perPage);
    }
}
```

**Two test cases:**
1. Suspend an active tenant → `is_active` becomes false, audit event created with `tenant.suspend` action
2. Suspend an already suspended tenant → throws `TenantAlreadySuspendedException`

### 9. PlatformAdminService — `app/Modules/Platform/Services/PlatformAdminService.php`

```php
<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Modules\Platform\Exceptions\PlatformAdminCannotDeactivateSelfException;
use App\Modules\Platform\Exceptions\UserAlreadyActiveException;
use App\Modules\Platform\Exceptions\UserAlreadyDeactivatedException;
use App\Modules\Platform\Models\AuditEvent;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformAdminService
{
    public function create(array $data, int $createdByUserId, string $ip): User
    {
        try {
            $user = User::create([
                'public_id' => (string) Str::uuid7(),
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? $data['name_ar'],
                'email' => $data['email'],
                'password' => $data['password'],
                'account_type' => AccountType::PlatformAdmin,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            AuditEvent::create([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $createdByUserId,
                'action' => AuditAction::PlatformAdminCreate->value,
                'entity_type' => 'platform_admin',
                'entity_id' => $user->public_id,
                'payload' => ['email' => $user->email],
                'ip_address' => $ip,
            ]);

            return $user;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin creation failed', [
                'action' => 'platform_admin.create',
                'entity_type' => 'platform_admin',
                'created_by' => $createdByUserId,
                'email' => $data['email'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function update(User $admin, array $data, int $updatedByUserId, string $ip): User
    {
        try {
            if (isset($data['name_en']) && empty($data['name_en'])) {
                $data['name_en'] = $data['name_ar'] ?? $admin->name_ar;
            }

            $admin->update($data);

            AuditEvent::create([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $updatedByUserId,
                'action' => AuditAction::PlatformAdminUpdate->value,
                'entity_type' => 'platform_admin',
                'entity_id' => $admin->public_id,
                'payload' => $data,
                'ip_address' => $ip,
            ]);

            return $admin->fresh();
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin update failed', [
                'action' => 'platform_admin.update',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deactivate(User $admin, int $deactivatedByUserId, string $ip): User
    {
        if (! $admin->is_active || $admin->deleted_at !== null) {
            throw new UserAlreadyDeactivatedException;
        }

        try {
            return DB::transaction(function () use ($admin, $deactivatedByUserId, $ip) {
                $admin->update(['is_active' => false]);
                $admin->delete();

                AuditEvent::create([
                    'public_id' => (string) Str::uuid7(),
                    'user_id' => $deactivatedByUserId,
                    'action' => AuditAction::PlatformAdminDeactivate->value,
                    'entity_type' => 'platform_admin',
                    'entity_id' => $admin->public_id,
                    'payload' => ['email' => $admin->email],
                    'ip_address' => $ip,
                ]);

                return $admin->fresh();
            });
        } catch (UserAlreadyDeactivatedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin deactivation failed', [
                'action' => 'platform_admin.deactivate',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reactivate(User $admin, int $reactivatedByUserId, string $ip): User
    {
        if ($admin->is_active && $admin->deleted_at === null) {
            throw new UserAlreadyActiveException;
        }

        try {
            return DB::transaction(function () use ($admin, $reactivatedByUserId, $ip) {
                $admin->update(['is_active' => true]);
                $admin->restore();

                AuditEvent::create([
                    'public_id' => (string) Str::uuid7(),
                    'user_id' => $reactivatedByUserId,
                    'action' => AuditAction::PlatformAdminReactivate->value,
                    'entity_type' => 'platform_admin',
                    'entity_id' => $admin->public_id,
                    'payload' => ['email' => $admin->email],
                    'ip_address' => $ip,
                ]);

                return $admin->fresh();
            });
        } catch (UserAlreadyActiveException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('platform')->error('Platform admin reactivation failed', [
                'action' => 'platform_admin.reactivate',
                'entity_id' => $admin->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function list(?string $search = null, int $perPage = 15): CursorPaginator
    {
        $query = User::where('account_type', AccountType::PlatformAdmin->value)
            ->orderBy('id');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_ar', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return $query->cursorPaginate($perPage);
    }
}
```

### 10. PlatformImpersonationService — `app/Modules/Platform/Services/PlatformImpersonationService.php`

```php
<?php

namespace App\Modules\Platform\Services;

use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Modules\Platform\Exceptions\CannotImpersonatePlatformAdminException;
use App\Modules\Platform\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PlatformImpersonationService
{
    public function startImpersonation(
        Tenant $tenant,
        string $targetUserPublicId,
        int $adminUserId,
        string $adminPublicId,
        string $ip
    ): array {
        tenancy()->initialize($tenant);

        $targetUser = \App\Models\User::where('public_id', $targetUserPublicId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->firstOrFail();

        if ($targetUser->account_type === AccountType::PlatformAdmin) {
            tenancy()->end();
            throw new CannotImpersonatePlatformAdminException;
        }

        $tokenName = "impersonated-by:{$adminPublicId}";
        $plainTextToken = $targetUser->createToken($tokenName, ['impersonated'], now()->addHour())->plainTextToken;

        tenancy()->end();

        AuditEvent::create([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $adminUserId,
            'action' => AuditAction::ImpersonationStart->value,
            'entity_type' => 'impersonation',
            'entity_id' => $targetUserPublicId,
            'payload' => [
                'tenant_slug' => $tenant->slug,
                'tenant_public_id' => $tenant->public_id,
                'impersonated_user_public_id' => $targetUserPublicId,
            ],
            'ip_address' => $ip,
        ]);

        return [
            'token' => $plainTextToken,
            'user' => $targetUser,
            'tenant' => $tenant,
            'expires_at' => now()->addHour()->toIso8601String(),
        ];
    }

    public function endImpersonation(int $adminUserId, string $adminPublicId, string $tenantPublicId, string $ip): void
    {
        AuditEvent::create([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $adminUserId,
            'action' => AuditAction::ImpersonationEnd->value,
            'entity_type' => 'impersonation',
            'entity_id' => $adminPublicId,
            'payload' => ['tenant_public_id' => $tenantPublicId],
            'ip_address' => $ip,
        ]);
    }

    public function listActiveSessions()
    {
        return AuditEvent::where('action', AuditAction::ImpersonationStart->value)
            ->whereNotIn('public_id', function ($query) {
                $query->select('a2.payload->impersonated_user_public_id')
                    ->from('audit_events as a2')
                    ->where('a2.action', AuditAction::ImpersonationEnd->value);
            })
            ->orderByDesc('created_at')
            ->cursorPaginate(15);
    }
}
```

**Two test cases:**
1. Start impersonation with a valid tenant user → returns token, audit event exists in central DB with `impersonation.start` action
2. Start impersonation with a platform_admin user → throws `CannotImpersonatePlatformAdminException`

### 11. RunTenantMigrationsJob — `app/Jobs/RunTenantMigrationsJob.php`

```php
<?php

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Modules\Platform\Models\AuditEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Jobs\MigrateDatabase;

class RunTenantMigrationsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public Tenant $tenant,
        public int $adminUserId,
        public string $adminPublicId,
        public string $ip,
    ) {}

    public function handle(): void
    {
        try {
            tenancy()->initialize($this->tenant);

            $migrateJob = new MigrateDatabase($this->tenant);
            $migrateJob->handle();

            tenancy()->end();

            AuditEvent::create([
                'public_id' => (string) \Illuminate\Support\Str::uuid7(),
                'user_id' => $this->adminUserId,
                'action' => AuditAction::TenantRunMigrations->value,
                'entity_type' => 'tenant',
                'entity_id' => $this->tenant->public_id,
                'payload' => ['status' => 'completed'],
                'ip_address' => $this->ip,
            ]);

            Log::channel('platform')->info('Tenant migrations completed', [
                'action' => 'tenant.run_migrations',
                'entity_id' => $this->tenant->public_id,
            ]);
        } catch (\Throwable $e) {
            tenancy()->end();

            AuditEvent::create([
                'public_id' => (string) \Illuminate\Support\Str::uuid7(),
                'user_id' => $this->adminUserId,
                'action' => AuditAction::TenantRunMigrations->value,
                'entity_type' => 'tenant',
                'entity_id' => $this->tenant->public_id,
                'payload' => ['status' => 'failed', 'error' => $e->getMessage()],
                'ip_address' => $this->ip,
            ]);

            Log::channel('platform')->error('Tenant migrations failed', [
                'action' => 'tenant.run_migrations',
                'entity_id' => $this->tenant->public_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

### 12. Exceptions — `app/Modules/Platform/Exceptions/`

```php
<?php

namespace App\Modules\Platform\Exceptions;

use Exception;

class TenantAlreadySuspendedException extends Exception
{
    public function __construct()
    {
        parent::__construct('This tenant is already suspended.');
    }
}

class TenantAlreadyActiveException extends Exception
{
    public function __construct()
    {
        parent::__construct('This tenant is already active.');
    }
}

class CannotImpersonateSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot impersonate yourself.');
    }
}

class CannotImpersonatePlatformAdminException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot impersonate a platform administrator.');
    }
}

class PlatformAdminCannotDeactivateSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('You cannot deactivate your own account.');
    }
}
```

Note: `UserAlreadyActiveException` and `UserAlreadyDeactivatedException` already exist in `App\Modules\Iam\Exceptions\`. Reuse them for `PlatformAdminService` (deactivate/reactivate) since the logic is identical.

### 13. Domain Events

All events implement `ShouldDispatchAfterCommit`:

```php
<?php

namespace App\Modules\Platform\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\ShouldDispatchAfterCommit;
use App\Models\Tenant;

class TenantProvisioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}

class TenantSuspended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}

class TenantReactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}

class TenantUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Tenant $tenant) {}
}
```

Similar pattern for `PlatformAdminCreated`, `PlatformAdminDeactivated`, `PlatformAdminReactivated`, `ImpersonationStarted`, `ImpersonationEnded`.

### 14. Routes — `routes/api/v1/platform.php`

```php
<?php

use App\Modules\Platform\Controllers\PlatformAdminController;
use App\Modules\Platform\Controllers\PlatformAuditEventController;
use App\Modules\Platform\Controllers\PlatformAuthController;
use App\Modules\Platform\Controllers\PlatformImpersonationController;
use App\Modules\Platform\Controllers\PlatformTenantController;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/platform/auth')->group(function () {
    Route::post('login', [PlatformAuthController::class, 'login'])
        ->middleware('throttle:' . RateLimits::AUTH_LOGIN);
    Route::post('logout', [PlatformAuthController::class, 'logout'])
        ->middleware('auth:sanctum');
    Route::get('me', [PlatformAuthController::class, 'me'])
        ->middleware('auth:sanctum');
});

Route::prefix('v1/platform')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('admins', [PlatformAdminController::class, 'index'])
        ->middleware('throttle:' . RateLimits::LIST);
    Route::post('admins', [PlatformAdminController::class, 'store'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::get('admins/{admin}', [PlatformAdminController::class, 'show']);
    Route::put('admins/{admin}', [PlatformAdminController::class, 'update'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('admins/{admin}/deactivate', [PlatformAdminController::class, 'deactivate'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('admins/{admin}/reactivate', [PlatformAdminController::class, 'reactivate'])
        ->middleware('throttle:' . RateLimits::MUTATE);

    Route::get('tenants', [PlatformTenantController::class, 'index'])
        ->middleware('throttle:' . RateLimits::LIST);
    Route::post('tenants', [PlatformTenantController::class, 'store'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show']);
    Route::put('tenants/{tenant}', [PlatformTenantController::class, 'update'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('tenants/{tenant}/run-migrations', [PlatformTenantController::class, 'runMigrations'])
        ->middleware('throttle:' . RateLimits::MUTATE);

    Route::post('tenants/{tenant}/impersonate', [PlatformImpersonationController::class, 'start'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::post('tenants/{tenant}/leave-impersonation', [PlatformImpersonationController::class, 'leave'])
        ->middleware('throttle:' . RateLimits::MUTATE);
    Route::get('impersonation-sessions', [PlatformImpersonationController::class, 'activeSessions'])
        ->middleware('throttle:' . RateLimits::LIST);

    Route::get('audit-events', [PlatformAuditEventController::class, 'index'])
        ->middleware('throttle:' . RateLimits::LIST);
});
```

**Register in `routes/api.php`:**

```php
<?php

require __DIR__.'/v1/platform.php';
```

Platform routes go in `routes/api.php` (central), NOT in `routes/tenant.php`.

### 15. bootstrap/app.php Updates

Register middleware alias, Platform exceptions, and rate limiters:

```php
// Add to middleware aliases:
$middleware->alias([
    'capability' => RequireCapability::class,
    'platform.admin' => RequirePlatformAdmin::class,  // NEW
]);

// Add Platform exceptions to renderable():
$exceptions->renderable(fn (TenantAlreadySuspendedException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (TenantAlreadyActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (CannotImpersonateSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (CannotImpersonatePlatformAdminException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn (PlatformAdminCannotDeactivateSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
```

### 16. RequireCapability Impersonation Detection

Update `app/Http/Middleware/RequireCapability.php` to detect impersonation tokens:

```php
// Add at the top of handle(), after user null check:
$impersonatedBy = null;
if ($user->currentAccessToken() && str_starts_with($user->currentAccessToken()->name, 'impersonated-by:')) {
    $impersonatedBy = str_replace('impersonated-by:', '', $user->currentAccessToken()->name);
    // Impersonated user — still check capabilities, but log is marked separately
}
// Existing tenant admin bypass and capability check logic continues unchanged
```

This is lightweight — we detect and pass through. Full impersonation audit logging in tenant DB belongs to Spec 015.

### 17. PlatformAdminResource — `app/Modules/Platform/Resources/PlatformAdminResource.php`

```php
<?php

namespace App\Modules\Platform\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'email' => $this->email,
            'account_type' => $this->account_type->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

### 18. PlatformTenantResource — `app/Modules/Platform/Resources/PlatformTenantResource.php`

```php
<?php

namespace App\Modules\Platform\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformTenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'database_name' => $this->database_name,
            'logo_path' => $this->logo_path,
            'default_language' => $this->default_language,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

### 19. AuditEventResource — `app/Modules/Platform/Resources/AuditEventResource.php`

```php
<?php

namespace App\Modules\Platform\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'user_id' => $this->user?->public_id,
            'action' => $this->action instanceof \App\Enums\AuditAction ? $this->action->value : $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'payload' => $this->payload,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

### 20. Request Validation Classes

```php
<?php

namespace App\Modules\Platform\Requests;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}

class UpdatePlatformAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('admin')->id)],
        ];
    }
}

class PlatformLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug', 'alpha_dash'],
            'domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:100', 'timezone'],
            'default_language' => ['nullable', 'integer', 'in:1,2'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
        ];
    }
}

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:100', 'timezone'],
            'default_language' => ['nullable', 'integer', 'in:1,2'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
            // slug and database_name are intentionally excluded — immutable
        ];
    }
}

class ImpersonateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'uuid'],
        ];
    }
}
```

---

## Execution Order

| Step | What | Depends On |
|------|------|------------|
| 1 | Create `AuditAction` enum in `app/Enums/` | None |
| 2 | Create `RequirePlatformAdmin` middleware | Step 1 |
| 3 | Create central migrations: `audit_events` + `users` column alignment | None |
| 4 | Create `AuditEvent` model (extends `CentralModel`) | Step 3 |
| 5 | Update `User` model: add `isPlatformAdmin()` method | None |
| 6 | Update `Tenant` model: add `getRouteKeyName()`, update `Str::uuid7()` | None |
| 7 | Create all Platform exceptions in `app/Modules/Platform/Exceptions/` | None |
| 8 | Create all Platform events (`ShouldDispatchAfterCommit`) in `app/Modules/Platform/Events/` | None |
| 9 | Create all Platform form requests | Step 1 |
| 10 | Create all Platform resources | None |
| 11 | Create `PlatformAuthService` | Steps 1, 4 |
| 12 | Create `PlatformAdminService` | Steps 1, 4 |
| 13 | Create `PlatformTenantService` (wraps existing `TenantProvisioningService`) | Steps 1, 4 |
| 14 | Create `PlatformImpersonationService` | Steps 1, 4 |
| 15 | Create `RunTenantMigrationsJob` | Steps 1, 4 |
| 16 | Create all Platform controllers | Steps 11-14 |
| 17 | Create `routes/api/v1/platform.php` | Step 16 |
| 18 | Register platform routes in `routes/api.php` | Step 17 |
| 19 | Register `RequirePlatformAdmin` middleware + Platform exceptions + rate limiters in `bootstrap/app.php` | Steps 2, 7 |
| 20 | Delete old `app/Services/Platform/ImpersonationService.php` | Step 14 |
| 21 | Update `TenantProvisioningService` with logging | Step 13 |
| 22 | Update `RequireCapability` middleware for impersonation detection | None |
| 23 | Run `vendor/bin/pint --dirty --format agent` | All code |
| 24 | Create Pest feature tests | All code |

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/v1/platform/auth/login` | Public (rate-limited) | Platform admin login |
| POST | `/api/v1/platform/auth/logout` | auth:sanctum | Platform admin logout |
| GET | `/api/v1/platform/auth/me` | auth:sanctum + platform.admin | Get current admin profile |
| GET | `/api/v1/platform/admins` | auth:sanctum + platform.admin | List platform admins (cursor paginated) |
| POST | `/api/v1/platform/admins` | auth:sanctum + platform.admin | Create platform admin |
| GET | `/api/v1/platform/admins/{admin}` | auth:sanctum + platform.admin | Show platform admin |
| PUT | `/api/v1/platform/admins/{admin}` | auth:sanctum + platform.admin | Update platform admin |
| POST | `/api/v1/platform/admins/{admin}/deactivate` | auth:sanctum + platform.admin | Deactivate platform admin |
| POST | `/api/v1/platform/admins/{admin}/reactivate` | auth:sanctum + platform.admin | Reactivate platform admin |
| GET | `/api/v1/platform/tenants` | auth:sanctum + platform.admin | List tenants (cursor paginated) |
| POST | `/api/v1/platform/tenants` | auth:sanctum + platform.admin | Provision new tenant |
| GET | `/api/v1/platform/tenants/{tenant}` | auth:sanctum + platform.admin | Show tenant details |
| PUT | `/api/v1/platform/tenants/{tenant}` | auth:sanctum + platform.admin | Update tenant profile |
| POST | `/api/v1/platform/tenants/{tenant}/suspend` | auth:sanctum + platform.admin | Suspend tenant |
| POST | `/api/v1/platform/tenants/{tenant}/reactivate` | auth:sanctum + platform.admin | Reactivate tenant |
| POST | `/api/v1/platform/tenants/{tenant}/run-migrations` | auth:sanctum + platform.admin | Run tenant migrations (async) |
| POST | `/api/v1/platform/tenants/{tenant}/impersonate` | auth:sanctum + platform.admin | Start impersonation |
| POST | `/api/v1/platform/tenants/{tenant}/leave-impersonation` | auth:sanctum + platform.admin | End impersonation |
| GET | `/api/v1/platform/impersonation-sessions` | auth:sanctum + platform.admin | List active impersonation sessions |
| GET | `/api/v1/platform/audit-events` | auth:sanctum + platform.admin | List audit events (cursor paginated) |

---

## What to Test Manually

1. **Platform admin login:** Create a platform admin via tinker, then POST `/v1/platform/auth/login` → receive token and user object. Verify token works on `/v1/platform/auth/me`.
2. **Platform admin login rejection:** Try to login with a tenant admin email → 422 "Platform login only".
3. **Platform admin login rate limiting:** Hit login 6 times rapidly → 429 on 6th attempt.
4. **Tenant provisioning:** POST `/v1/platform/tenants` with valid data → tenant created, central DB row exists, tenant DB created, audit event in `audit_events`.
5. **Tenant suspension + reactivation:** Suspend tenant → `is_active = false`. Verify `CheckTenantStatus` middleware blocks tenant API requests (403). Reactivate → `is_active = true` → tenant API works again.
6. **Tenant update immutability:** PUT `/v1/platform/tenants/{tenant}` with `slug` in payload → verify `slug` is ignored (not changed).
7. **Run migrations:** POST `/v1/platform/tenants/{tenant}/run-migrations` → 202 Accepted. Verify migrations ran on tenant DB.
8. **Impersonation start:** POST `/v1/platform/tenants/{tenant}/impersonate` with `user_public_id` → receive token. Use token with `X-Tenant` header on tenant API → works. Audit event in central DB with `impersonation.start`.
9. **Impersonation self-check:** Try to impersonate yourself → 422.
10. **Deactivate self-check:** Try to deactivate your own platform admin account → 422.
11. **Audit events list:** GET `/v1/platform/audit-events` → returns events in chronological order with cursor pagination.
12. **Central-only access:** Platform endpoints work WITHOUT `X-Tenant` header. Tenant endpoints REQUIRE it.
13. **Cursor pagination:** GET `/v1/platform/tenants?cursor=XXX` → returns `{data, next_cursor, has_more}`.
14. **Platform admin CRUD cycle:** Create → show → update → deactivate → reactivate → verify each state change.

---

→ **Next:** Implement in order per Execution Order table above.