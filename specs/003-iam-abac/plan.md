# Implementation Plan: 003 IAM & ABAC

> **Spec:** `specs/003-iam-abac/spec.md`
> **Status:** `approved`
> **Branch:** `feat/003-iam-abac` from `main`

---

## Open Questions Resolved

1. **Login mechanism:** SPA cookies with CSRF for frontend. `POST /api/v1/iam/auth/login` uses `Auth::guard('web')->attempt()` and returns Sanctum token. MVP: token-based (simplest for API testing); SPA cookie mode can be toggled via Sanctum config.
2. **`user_capability_grants.reason`:** Required on every direct user grant, including re-grants. No exceptions.
3. **Grant revocation:** `revoked_at` soft-revoke only. Never hard-delete rows. Re-granting creates a new row with new `granted_at`. This preserves full audit trail.
4. **Position deactivation cascading:** Grants remain active but inert (no one holds the position, so no one inherits). Admin must manually revoke if desired.
5. **"Grant all" for tenant admin provisioning:** A seeder `CapabilitySeeder` populates all 25 MVP capabilities and assigns `iam.manage_*` capabilities to the initial admin position with `tenant` scope. This runs during tenant provisioning.
6. **`monitoring_scope_grants.blueprint_category_id`:** Nullable FK placeholder. FK constraint deferred to Spec 004. Column exists but no FK check until `blueprint_categories` table is created.
7. **Out-of-office:** Simple boolean + `out_of_office_delegate_user_id` on `users` table. The `delegations` table handles scoped delegations separately.
8. **IamPolicy caching:** Per-request memory cache using a singleton resolved from the service container. Clear at request end via a terminating callback.

---

## Technical Approach

Build the IAM module under `app/Modules/Iam/` following the established pattern from `app/Modules/Organization/`. All IAM tables live in the tenant DB (no `tenant_id` columns). Sanctum token authentication for MVP. ABAC policy engine as a service class consumed via `RequireCapability` middleware and direct service calls. Replace `RequireTenantAdmin` with `RequireCapability` throughout.

### Key Decisions

- **User model extends Authenticatable, NOT TenantModel.** User needs Sanctum's `HasApiTokens` and Laravel's auth contracts. Extract `HasPublicId` trait from `TenantModel` so both share UUID7 + route key binding logic.
- **Authentication: token-based for MVP.** Login returns a Sanctum plain text token. Frontend stores it and sends via `Authorization: Bearer` header. SPA cookie mode can be enabled later via config.
- **Account types as a backed enum:** `App\Enums\AccountType` with `INTERNAL_USER(1)`, `TENANT_ADMIN(2)`, `EXTERNAL_AUDITOR(3)`, `PLATFORM_ADMIN(4)`.
- **ScopeType as a backed enum:** `App\Enums\ScopeType` with values matching the ERD.
- **Bilingual fallback in service layer** (matching Organization pattern): `name_en` falls back to `name_ar` when null/empty before persisting.
- **Grants are append-only with `revoked_at`:** Never hard-delete. New grant = new row. This gives full audit trail.
- **IamPolicy uses per-request memory cache:** `app(IamPolicy::class)` resolves a singleton. Cache is built on first `check()` call and persists for the request lifecycle.
- **RequireCapability middleware** replaces RequireTenantAdmin. `RequireCapability::class` accepts capability key as parameter. Checks via `IamPolicy::check()`.
- **Tenant admin bootstrapping:** Tenant provisioning seed includes a command that creates an admin user and assigns `iam.manage_*` capabilities to their position.
- **Position.currentOccupant()** (commented out in Spec 002) is activated in this spec by creating the `UserPositionAssignment` model and uncommenting the relationship.

---

## Affected Modules / Files

### New Files

```
app/
├── Enums/
│   ├── AccountType.php
│   ├── ScopeType.php
│   └── DelegationScopeType.php
├── Models/
│   └── Traits/
│       └── HasPublicId.php          (extracted from TenantModel)
├── Modules/Iam/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── UserController.php
│   │   ├── PositionAssignmentController.php
│   │   ├── CapabilityController.php
│   │   ├── PositionCapabilityGrantController.php
│   │   ├── UserCapabilityGrantController.php
│   │   ├── MonitoringScopeGrantController.php
│   │   ├── AuditGrantController.php
│   │   └── DelegationController.php
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── UserService.php
│   │   ├── PositionAssignmentService.php
│   │   ├── CapabilityService.php
│   │   ├── GrantService.php
│   │   ├── MonitoringScopeService.php
│   │   ├── DelegationService.php
│   │   ├── AuditGrantService.php
│   │   └── IamPolicy.php
│   ├── Models/
│   │   ├── User.php                  (updated, not new — in app/Models/)
│   │   ├── UserPositionAssignment.php
│   │   ├── Capability.php
│   │   ├── PositionCapabilityGrant.php
│   │   ├── UserCapabilityGrant.php
│   │   ├── MonitoringScopeGrant.php
│   │   ├── AuditGrant.php
│   │   └── Delegation.php
│   ├── Requests/
│   │   ├── LoginRequest.php
│   │   ├── StoreUserRequest.php
│   │   ├── UpdateUserRequest.php
│   │   ├── AssignPositionRequest.php
│   │   ├── EndPositionRequest.php
│   │   ├── SetPrimaryPositionRequest.php
│   │   ├── UpdateCapabilityRequest.php
│   │   ├── GrantPositionCapabilityRequest.php
│   │   ├── GrantUserCapabilityRequest.php
│   │   ├── GrantMonitoringScopeRequest.php
│   │   ├── GrantAuditGrantRequest.php
│   │   ├── StoreDelegationRequest.php
│   │   └── UpdateDelegationRequest.php
│   ├── Resources/
│   │   ├── AuthTokenResource.php
│   │   ├── UserResource.php
│   │   ├── UserDetailResource.php
│   │   ├── PositionAssignmentResource.php
│   │   ├── CapabilityResource.php
│   │   ├── PositionCapabilityGrantResource.php
│   │   ├── UserCapabilityGrantResource.php
│   │   ├── MonitoringScopeGrantResource.php
│   │   ├── AuditGrantResource.php
│   │   ├── DelegationResource.php
│   │   └── EffectiveCapabilityResource.php
│   ├── Events/
│   │   ├── UserCreated.php
│   │   ├── UserLoggedIn.php
│   │   ├── UserLoggedOut.php
│   │   ├── UserDeactivated.php
│   │   ├── UserReactivated.php
│   │   ├── PositionAssigned.php
│   │   ├── PositionEnded.php
│   │   ├── PrimaryPositionChanged.php
│   │   ├── CapabilityGranted.php
│   │   ├── CapabilityRevoked.php
│   │   ├── MonitoringScopeGranted.php
│   │   ├── MonitoringScopeRevoked.php
│   │   ├── AuditGrantCreated.php
│   │   ├── AuditGrantRevoked.php
│   │   ├── DelegationCreated.php
│   │   ├── DelegationRevoked.php
│   │   ├── UserMarkedOutOfOffice.php
│   │   └── UserMarkedBackInOffice.php
│   └── Exceptions/
│       ├── UserAlreadyActiveException.php
│       ├── UserAlreadyDeactivatedException.php
│       ├── CannotDelegateToSelfException.php
│       ├── PrimaryPositionAlreadyAssignedException.php
│       ├── CannotRevokeSystemCapabilityKeyException.php
│       └── DuplicateGrantException.php
├── Http/Middleware/
│   └── RequireCapability.php         (replaces RequireTenantAdmin)
database/
├── migrations/tenant/
│   ├── 2026_06_09_000000_alter_users_add_iam_columns.php
│   ├── 2026_06_09_000001_create_capabilities_table.php
│   ├── 2026_06_09_000002_create_user_position_assignments_table.php
│   ├── 2026_06_09_000003_create_position_capability_grants_table.php
│   ├── 2026_06_09_000004_create_user_capability_grants_table.php
│   ├── 2026_06_09_000005_create_monitoring_scope_grants_table.php
│   ├── 2026_06_09_000006_create_delegations_table.php
│   └── 2026_06_09_000007_create_audit_grants_table.php
├── seeders/
│   └── CapabilitySeeder.php
routes/
└── api/
    └── v1/
        └── iam.php
tests/
└── Feature/Modules/Iam/
    ├── AuthenticationTest.php
    ├── UserTest.php
    ├── PositionAssignmentTest.php
    ├── CapabilityTest.php
    ├── PositionCapabilityGrantTest.php
    ├── UserCapabilityGrantTest.php
    ├── MonitoringScopeGrantTest.php
    ├── AuditGrantTest.php
    ├── DelegationTest.php
    ├── OutOfOfficeTest.php
    └── IamPolicyTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `app/Models/User.php` | Major rewrite: add IAM fields, `HasApiTokens`, `HasPublicId` trait, relationships, casts |
| `app/Models/TenantModel.php` | Extract `HasPublicId` trait; use trait instead of inline code |
| `app/Modules/Organization/Models/Position.php` | Uncomment `currentOccupant()` relationship |
| `routes/api/v1/organization.php` | Replace `RequireTenantAdmin` with `RequireCapability` middleware |
| `routes/tenant.php` | Add `require __DIR__.'/api/v1/iam.php'` |
| `bootstrap/app.php` | Register IAM module exceptions + `RequireCapability` middleware alias |
| `app/Providers/AppServiceProvider.php` | Register `IamPolicy` singleton |

---

## Implementation Notes

### 1. Extract `HasPublicId` Trait

**File:** `app/Models/Traits/HasPublicId.php`

```php
<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
```

**File:** `app/Models/TenantModel.php` — Refactor to use the trait:

```php
<?php

namespace App\Models;

use App\Models\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use HasPublicId;
}
```

Models that previously used `SoftDeletes` by inheriting from old `TenantModel` still add `SoftDeletes` trait individually. This refactor changes nothing about existing models — `HasPublicId` is the same code, just in a trait.

---

### 2. Enums

**File:** `app/Enums/AccountType.php`

```php
<?php

namespace App\Enums;

enum AccountType: int
{
    case INTERNAL_USER = 1;
    case TENANT_ADMIN = 2;
    case EXTERNAL_AUDITOR = 3;
    case PLATFORM_ADMIN = 4;
}
```

**File:** `app/Enums/ScopeType.php`

```php
<?php

namespace App\Enums;

enum ScopeType: int
{
    case TENANT = 1;
    case OWN_DEPARTMENT = 2;
    case SPECIFIC_DEPARTMENT = 3;
    case DEPARTMENT_TREE = 4;
    case OWN_TASKS = 5;
    case AUDIT_GRANT = 6;
}
```

**File:** `app/Enums/DelegationScopeType.php`

```php
<?php

namespace App\Enums;

enum DelegationScopeType: int
{
    case ALL = 1;
    case BLUEPRINT_CATEGORY = 2;
    case STAGE_TYPE = 3;
    case BLUEPRINT_CATEGORY_AND_STAGE_TYPE = 4;
}
```

---

### 3. Migrations

All migrations go in `database/migrations/tenant/`. Order follows FK dependency chain.

#### `2026_06_09_000000_alter_users_add_iam_columns.php`

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
            $table->uuid('public_id')->unique()->after('id');
            $table->string('name_ar')->after('name');
            $table->string('mobile', 30)->nullable()->after('email');
            $table->string('employee_id', 50)->nullable()->unique()->after('mobile');
            $table->unsignedTinyInteger('preferred_language')->default(1)->after('employee_id');
            $table->boolean('is_active')->default(true)->after('preferred_language');
            $table->boolean('is_out_of_office')->default(false)->after('is_active');
            $table->foreignId('out_of_office_delegate_user_id')->nullable()
                ->constrained('users')->nullOnDelete()->after('is_out_of_office');
            $table->softDeletes()->after('updated_at');
        });

        // Rename 'name' column to 'name_en'
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'name_en');
        });

        // Make name_en nullable (name_ar is the required field)
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_en')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['out_of_office_delegate_user_id']);
            $table->dropColumn([
                'public_id',
                'name_ar',
                'mobile',
                'employee_id',
                'preferred_language',
                'is_active',
                'is_out_of_office',
                'out_of_office_delegate_user_id',
                'deleted_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name_en', 'name');
            $table->string('name')->nullable(false)->change();
        });
    }
};
```

**IMPORTANT:** The `name` → `name_en` rename means we must update `UserFactory` and any existing references to `name`. The `name_ar` column is NOT nullable and has no default — it must always be provided.

#### `2026_06_09_000001_create_capabilities_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_system_defined')->default(true);
            $table->timestamps();

            $table->index('is_system_defined');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
```

#### `2026_06_09_000002_create_user_position_assignments_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['position_id', 'ended_at']);
            $table->index(['user_id', 'is_primary', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_position_assignments');
    }
};
```

#### `2026_06_09_000003_create_position_capability_grants_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_capability_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['position_id', 'revoked_at']);
            $table->index(['capability_id', 'revoked_at']);
            $table->index('scope_department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_capability_grants');
    }
};
```

#### `2026_06_09_000004_create_user_capability_grants_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capability_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason');

            $table->index(['user_id', 'revoked_at']);
            $table->index(['capability_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capability_grants');
    }
};
```

#### `2026_06_09_000005_create_monitoring_scope_grants_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_scope_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type');
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('blueprint_category_id')->nullable();
            // NOTE: FK to blueprint_categories deferred until Spec 004 creates that table
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('scope_department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_scope_grants');
    }
};
```

#### `2026_06_09_000006_create_delegations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('delegator_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedTinyInteger('scope_type');
            $table->unsignedBigInteger('blueprint_category_id')->nullable();
            $table->unsignedBigInteger('stage_type_id')->nullable();
            // NOTE: FKs for blueprint_category_id and stage_type_id deferred until Specs 004/006
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['delegator_user_id', 'is_active', 'starts_at', 'ends_at']);
            $table->index(['delegate_user_id', 'is_active']);
            $table->index('public_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
```

**NOTE:** `blueprint_category_id` and `stage_type_id` foreign keys are placeholders. Do NOT add `->constrained()` until their respective tables exist (Specs 004 and 006).

---

### 4. Models

All IAM models except `User` live in `App\Modules\Iam\Models`. `User` stays in `App\Models` since it's a framework model.

#### `User.php` (modified) — `app/Models/User.php`

```php
<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Traits\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name_ar',
    'name_en',
    'email',
    'password',
    'mobile',
    'employee_id',
    'account_type',
    'preferred_language',
    'is_active',
    'is_out_of_office',
    'out_of_office_delegate_user_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferred_language' => 'integer',
            'is_active' => 'boolean',
            'is_out_of_office' => 'boolean',
        ];
    }

    public function currentPositionAssignment(): HasOne
    {
        return $this->hasOne(\App\Modules\Iam\Models\UserPositionAssignment::class, 'user_id')
            ->where('is_primary', true)
            ->whereNull('ended_at');
    }

    public function positionAssignments(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\UserPositionAssignment::class, 'user_id');
    }

    public function activePositionAssignments(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\UserPositionAssignment::class, 'user_id')
            ->whereNull('ended_at');
    }

    public function userCapabilityGrants(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\UserCapabilityGrant::class);
    }

    public function monitoringScopeGrants(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\MonitoringScopeGrant::class);
    }

    public function delegationsAsDelegator(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\Delegation::class, 'delegator_user_id');
    }

    public function delegationsAsDelegate(): HasMany
    {
        return $this->hasMany(\App\Modules\Iam\Models\Delegation::class, 'delegate_user_id');
    }

    public function outOfOfficeDelegate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'out_of_office_delegate_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function isTenantAdmin(): bool
    {
        return $this->account_type === AccountType::TENANT_ADMIN;
    }

    public function isInternalUser(): bool
    {
        return $this->account_type === AccountType::INTERNAL_USER;
    }

    public function isExternalAuditor(): bool
    {
        return $this->account_type === AccountType::EXTERNAL_AUDITOR;
    }
}
```

**CRITICAL:** `User` does NOT extend `TenantModel`. It extends `Authenticatable` and uses the `HasPublicId` trait directly. It DOES use `SoftDeletes` because deactivated users are soft-deleted (not hard-deleted).

#### `Capability.php` — `app/Modules/Iam/Models/Capability.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name_ar', 'name_en', 'description', 'is_system_defined'])]
class Capability extends TenantModel
{
    public function positionCapabilityGrants(): HasMany
    {
        return $this->hasMany(PositionCapabilityGrant::class);
    }

    public function userCapabilityGrants(): HasMany
    {
        return $this->hasMany(UserCapabilityGrant::class);
    }

    protected function casts(): array
    {
        return [
            'is_system_defined' => 'boolean',
        ];
    }
}
```

**NOTE:** `Capability` does NOT use SoftDeletes. System-defined capabilities cannot be deleted at all. Tenant-created ones can be deactivated but not hard-deleted for audit purposes.

#### `UserPositionAssignment.php` — `app/Modules/Iam/Models/UserPositionAssignment.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Models\User;
use App\Modules\Organization\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPositionAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'position_id',
        'started_at',
        'ended_at',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true)->whereNull('ended_at');
    }
}
```

**NOTE:** `UserPositionAssignment` does NOT extend `TenantModel` and does NOT have `public_id`. It's a join table with history semantics — no external API routing needed. It uses regular Eloquent Model, not TenantModel, because it doesn't need UUID route binding.

#### `PositionCapabilityGrant.php` — `app/Modules/Iam/Models/PositionCapabilityGrant.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionCapabilityGrant extends Model
{
    protected $fillable = [
        'position_id',
        'capability_id',
        'scope_type',
        'scope_department_id',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
```

**NOTE:** `PositionCapabilityGrant` does NOT extend `TenantModel` and has NO `public_id`. Grants are administered, not route-bound. No soft delete — use `revoked_at` instead.

#### `UserCapabilityGrant.php` — `app/Modules/Iam/Models/UserCapabilityGrant.php`

Same pattern as `PositionCapabilityGrant` but with `user_id` instead of `position_id` and an additional `reason` field:

```php
<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCapabilityGrant extends Model
{
    protected $fillable = [
        'user_id',
        'capability_id',
        'scope_type',
        'scope_department_id',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
```

#### `MonitoringScopeGrant.php` — `app/Modules/Iam/Models/MonitoringScopeGrant.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringScopeGrant extends Model
{
    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_department_id',
        'blueprint_category_id',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    // TODO: Uncomment when Spec 004 creates BlueprintCategory model
    // public function blueprintCategory(): BelongsTo
    // {
    //     return $this->belongsTo(BlueprintCategory::class);
    // }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
```

#### `Delegation.php` — `app/Modules/Iam/Models/Delegation.php`

```php
<?php

namespace App\Modules\Iam\Models;

use App\Enums\DelegationScopeType;
use App\Models\Traits\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delegator_user_id',
    'delegate_user_id',
    'starts_at',
    'ends_at',
    'scope_type',
    'blueprint_category_id',
    'stage_type_id',
    'is_active',
])]
class Delegation extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'scope_type' => DelegationScopeType::class,
            'is_active' => 'boolean',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrentlyActive($query)
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }
}
```

**NOTE:** `Delegation` does NOT extend `TenantModel`. It uses `HasPublicId` trait directly because it needs UUID7 route binding but does NOT use SoftDeletes. Delegations are deactivated (`is_active = false`), not soft-deleted.

---

### 5. Position Model — Activate `currentOccupant()`

**File:** `app/Modules/Organization/Models/Position.php`

Find and replace the commented-out `currentOccupant()` method with:

```php
public function currentOccupant(): HasOne
{
    return $this->hasOne(\App\Modules\Iam\Models\UserPositionAssignment::class, 'position_id')
        ->where('is_primary', true)
        ->whereNull('ended_at');
}
```

Also add the import at the top. The `PositionResource` should be updated to include:

```php
'current_occupant' => $this->whenLoaded('currentOccupant', function () {
    $assignment = $this->currentOccupant;
    if ($assignment && $assignment->user) {
        return [
            'public_id' => $assignment->user->public_id,
            'name_ar' => $assignment->user->name_ar,
            'name_en' => $assignment->user->name_en ?? $assignment->user->name_ar,
        ];
    }
    return null;
}),
```

---

### 6. IamPolicy — The ABAC Engine

**File:** `app/Modules/Iam/Services/IamPolicy.php`

```php
<?php

namespace App\Modules\Iam\Services;

use App\Enums\ScopeType;
use App\Models\User;
use App\Modules\Organization\Models\Department;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Models\UserCapabilityGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class IamPolicy
{
    private ?Collection $capabilitiesCache = null;
    private ?int $cachedUserId = null;

    public function check(User $user, string $capability, ?ScopeType $scopeType = null, ?int $departmentId = null): bool
    {
        $effectiveCapabilities = $this->getEffectiveCapabilities($user);

        $matchingGrant = $effectiveCapabilities->first(
            fn($grant) => $grant->capability_key === $capability && $grant->revoked_at === null
        );

        if ($matchingGrant === null) {
            return false;
        }

        if ($scopeType === null && $departmentId === null) {
            return true;
        }

        return $this->scopeCoversDepartment(
            $matchingGrant->scope_type,
            $matchingGrant->scope_department_id,
            $user,
            $scopeType,
            $departmentId
        );
    }

    public function getEffectiveCapabilities(User $user): Collection
    {
        if ($this->capabilitiesCache !== null && $this->cachedUserId === $user->id) {
            return $this->capabilitiesCache;
        }

        $positionGrants = $this->getPositionGrants($user);
        $userGrants = $this->getUserGrants($user);

        $merged = $positionGrants->merge($userGrants);

        $this->capabilitiesCache = $merged;
        $this->cachedUserId = $user->id;

        return $merged;
    }

    public function hasCapability(User $user, string $capability): bool
    {
        return $this->check($user, $capability);
    }

    public function isOutOfOffice(User $user): bool
    {
        return (bool) $user->is_out_of_office;
    }

    public function resolveAssignee(User $user): User
    {
        if ($this->isOutOfOffice($user) && $user->out_of_office_delegate_user_id) {
            return $user->outOfOfficeDelegate;
        }

        return $user;
    }

    public function getActiveDelegate(User $user): ?User
    {
        return \App\Modules\Iam\Models\Delegation::where('delegator_user_id', $user->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderByDesc('created_at')
            ->first()
            ?->delegate;
    }

    public function clearCache(): void
    {
        $this->capabilitiesCache = null;
        $this->cachedUserId = null;
    }

    private function getPositionGrants(User $user): Collection
    {
        $primaryAssignment = $user->currentPositionAssignment;

        if ($primaryAssignment === null) {
            return collect();
        }

        return PositionCapabilityGrant::where('position_id', $primaryAssignment->position_id)
            ->whereNull('revoked_at')
            ->with('capability')
            ->get()
            ->map(fn($grant) => (object) [
                'capability_key' => $grant->capability->key,
                'scope_type' => $grant->scope_type,
                'scope_department_id' => $grant->scope_department_id,
                'source' => 'position',
                'revoked_at' => $grant->revoked_at,
            ]);
    }

    private function getUserGrants(User $user): Collection
    {
        return UserCapabilityGrant::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('capability')
            ->get()
            ->map(fn($grant) => (object) [
                'capability_key' => $grant->capability->key,
                'scope_type' => $grant->scope_type,
                'scope_department_id' => $grant->scope_department_id,
                'source' => 'user',
                'revoked_at' => $grant->revoked_at,
            ]);
    }

    private function scopeCoversDepartment(
        ScopeType $grantScopeType,
        ?int $grantScopeDepartmentId,
        User $user,
        ?ScopeType $requiredScopeType,
        ?int $requiredDepartmentId
    ): bool {
        return match ($grantScopeType) {
            ScopeType::TENANT => true,
            ScopeType::OWN_DEPARTMENT => $this->getUserDepartmentId($user) === $requiredDepartmentId,
            ScopeType::SPECIFIC_DEPARTMENT => $grantScopeDepartmentId === $requiredDepartmentId,
            ScopeType::DEPARTMENT_TREE => $this->departmentIsInTree($grantScopeDepartmentId, $requiredDepartmentId),
            ScopeType::OWN_TASKS => true,
            default => false,
        };
    }

    private function getUserDepartmentId(User $user): ?int
    {
        return $user->currentPositionAssignment?->position?->department_id;
    }

    private function departmentIsInTree(?int $ancestorId, ?int $descendantId): bool
    {
        if ($ancestorId === null || $descendantId === null) {
            return false;
        }

        if ($ancestorId === $descendantId) {
            return true;
        }

        $descendant = Department::find($descendantId);

        if ($descendant === null) {
            return false;
        }

        $current = $descendant;
        $maxDepth = 10;

        while ($current->parent_department_id !== null && $maxDepth-- > 0) {
            if ($current->parent_department_id === $ancestorId) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }
}
```

**Register as singleton in** `AppServiceProvider`:

```php
$this->app->singleton(\App\Modules\Iam\Services\IamPolicy::class);
```

**Clear cache on request terminate** — add to `AppServiceProvider` boot method:

```php
app()->terminating(function () {
    app(\App\Modules\Iam\Services\IamPolicy::class)->clearCache();
});
```

---

### 7. RequireCapability Middleware

**File:** `app/Http/Middleware/RequireCapability.php`

```php
<?php

namespace App\Http\Middleware;

use App\Modules\Iam\Services\IamPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    public function __construct(private IamPolicy $policy) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        // Tenant admins bypass capability checks for admin functions
        if ($user->isTenantAdmin()) {
            return $next($request);
        }

        if (!$this->policy->check($user, $capability)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
```

**Register middleware alias** in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'capability' => \App\Http\Middleware\RequireCapability::class,
    ]);
})
```

**Usage in routes:** `Route::middleware(['auth:sanctum', 'capability:iam.manage_users'])`

**CRITICAL:** The `isTenantAdmin()` bypass in `RequireCapability` is a temporary measure. Tenant admins should eventually receive all capabilities through position grants. The bypass ensures admin functions work immediately after provisioning. This must be replaced with proper capability grants once the provisioning seeder is thoroughly tested.

---

### 8. Update Organization Routes — Replace RequireTenantAdmin

**File:** `routes/api/v1/organization.php`

Replace ALL occurrences of `RequireTenantAdmin::class` with `RequireCapability::class` and add the capability string:

```php
// Before:
Route::middleware([RequireTenantAdmin::class])->group(function () {

// After:
Route::middleware(['capability:organization.manage'])->group(function () {
```

Also add the import and remove the old RequireTenantAdmin import:

```php
use App\Http\Middleware\RequireCapability;
```

**Do NOT delete the `RequireTenantAdmin.php` file** yet. It will be deprecated but kept for reference. Add a `@deprecated` comment.

---

### 9. CapabilitySeeder

**File:** `database/seeders/CapabilitySeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Modules\Iam\Models\Capability;
use Illuminate\Database\Seeder;

class CapabilitySeeder extends Seeder
{
    private const CAPABILITIES = [
        ['key' => 'task.view.organization', 'name_ar' => 'عرض مهام المؤسسة', 'name_en' => 'View Organization Tasks', 'description' => 'Can view tasks across the whole tenant, subject to classification rules.'],
        ['key' => 'task.view.department_touched', 'name_ar' => 'عرض مهام القسم', 'name_en' => 'View Department-Touched Tasks', 'description' => 'Can view tasks that have touched the user\'s department.'],
        ['key' => 'task.view.follow_up_scope', 'name_ar' => 'عرض نطاق المتابعة', 'name_en' => 'View Follow-Up Scope Tasks', 'description' => 'Can view active tasks inside assigned monitoring scopes.'],
        ['key' => 'task.view.own_participation', 'name_ar' => 'عرض مهامي', 'name_en' => 'View Own Participation', 'description' => 'Can view tasks the user initiated, currently owns, or previously owned.'],
        ['key' => 'task.classify.confidential', 'name_ar' => 'تصنيف المهام السرية', 'name_en' => 'Classify Confidential Tasks', 'description' => 'Can create or mark a task as confidential.'],
        ['key' => 'task.confidential.view_metadata', 'name_ar' => 'عرض بيانات المهام السرية', 'name_en' => 'View Confidential Metadata', 'description' => 'Can discover confidential task metadata without viewing full content.'],
        ['key' => 'task.confidential.view_override', 'name_ar' => 'تجاوز سرية المهام', 'name_en' => 'Override Confidential Access', 'description' => 'Can open confidential task content through justified, audited override.'],
        ['key' => 'task.confidential.manage_participants', 'name_ar' => 'إدارة مشاركين المهام السرية', 'name_en' => 'Manage Confidential Participants', 'description' => 'Can add or remove named confidential participants within granted scope.'],
        ['key' => 'task.override_assignment', 'name_ar' => 'تجاوز تعيين المهام', 'name_en' => 'Override Task Assignment', 'description' => 'Can reassign active stage/sub-stage assignees with mandatory reason.'],
        ['key' => 'task.cancel', 'name_ar' => 'إلغاء مهام', 'name_en' => 'Cancel Tasks', 'description' => 'Can cancel active tasks with mandatory reason.'],
        ['key' => 'task.suspend_resume', 'name_ar' => 'تعليق واستئناف المهام', 'name_en' => 'Suspend & Resume Tasks', 'description' => 'Can suspend or resume tasks.'],
        ['key' => 'blueprint.view_library', 'name_ar' => 'عرض مكتبة القوالب', 'name_en' => 'View Blueprint Library', 'description' => 'Can browse the Blueprint library.'],
        ['key' => 'blueprint.create.organization', 'name_ar' => 'إنشاء قوالب مؤسسية', 'name_en' => 'Create Organization Blueprints', 'description' => 'Can create organization-wide Blueprints.'],
        ['key' => 'blueprint.create.department', 'name_ar' => 'إنشاء قوالب قسم', 'name_en' => 'Create Department Blueprints', 'description' => 'Can create department-scoped Blueprints.'],
        ['key' => 'blueprint.manage', 'name_ar' => 'إدارة القوالب', 'name_en' => 'Manage Blueprints', 'description' => 'Can activate, deactivate, duplicate, or lock/manage Blueprints.'],
        ['key' => 'analytics.view.organization', 'name_ar' => 'عرض تحليلات المؤسسة', 'name_en' => 'View Organization Analytics', 'description' => 'Can view organization-wide analytics.'],
        ['key' => 'analytics.view.department', 'name_ar' => 'عرض تحليلات القسم', 'name_en' => 'View Department Analytics', 'description' => 'Can view department-level analytics.'],
        ['key' => 'analytics.view.individuals_in_department', 'name_ar' => 'عرض أداء الأفراد', 'name_en' => 'View Individual Metrics', 'description' => 'Can view individual employee metrics inside own department.'],
        ['key' => 'iam.manage_users', 'name_ar' => 'إدارة المستخدمين', 'name_en' => 'Manage Users', 'description' => 'Can create, deactivate, and transfer users.'],
        ['key' => 'iam.manage_positions', 'name_ar' => 'إدارة الهيكل التنظيمي', 'name_en' => 'Manage Organization Structure', 'description' => 'Can manage departments, positions, reporting lines, and grades.'],
        ['key' => 'iam.manage_capabilities', 'name_ar' => 'إدارة الصلاحيات', 'name_en' => 'Manage Capabilities', 'description' => 'Can assign capabilities and permission templates.'],
        ['key' => 'audit.view_task', 'name_ar' => 'عرض سجل المهام', 'name_en' => 'View Task Audit Trail', 'description' => 'Can view task-level audit trail for visible tasks.'],
        ['key' => 'audit.view_system', 'name_ar' => 'عرض سجل النظام', 'name_en' => 'View System Audit', 'description' => 'Can view system-wide user activity logs.'],
        ['key' => 'audit.create_grant', 'name_ar' => 'إنشاء صلاحيات المراجعة', 'name_en' => 'Create Audit Grants', 'description' => 'Can create external audit grants.'],
        ['key' => 'organization.manage', 'name_ar' => 'إدارة الهيكل التنظيمي', 'name_en' => 'Manage Organization', 'description' => 'Can manage departments, positions, grades, and calendars.'],
        ['key' => 'helpcenter.manage', 'name_ar' => 'إدارة مركز المساعدة', 'name_en' => 'Manage Help Center', 'description' => 'Can create, edit, publish, unpublish, and delete help articles.'],
        ['key' => 'helpcenter.view', 'name_ar' => 'عرض مركز المساعدة', 'name_en' => 'View Help Center', 'description' => 'Can browse and read published help articles.'],
    ];

    public function run(): void
    {
        foreach (self::CAPABILITIES as $cap) {
            Capability::create([
                'key' => $cap['key'],
                'name_ar' => $cap['name_ar'],
                'name_en' => $cap['name_en'],
                'description' => $cap['description'],
                'is_system_defined' => true,
            ]);
        }
    }
}
```

This seeder runs on the tenant DB connection. It creates 25 system-defined capabilities that cannot be deleted (their `key` and `is_system_defined` flag are immutable).

---

### 10. AuthController

**File:** `app/Modules/Iam/Controllers/AuthController.php`

```php
<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Requests\LoginRequest;
use App\Modules\Iam\Resources\AuthTokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): AuthTokenResource|JsonResponse
    {
        $credentials = $request->validated();

        if (!Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::guard('web')->user();

        if (!$user->is_active || $user->deleted_at !== null) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages([
                'email' => __('auth.inactive'),
            ]);
        }

        if ($user->account_type->value === 4) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages([
                'email' => __('auth.platform_admin_login_disabled'),
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextValue;

        return new AuthTokenResource($user, $token);
    }

    public function logout(): JsonResponse
    {
        auth()->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(): JsonResource
    {
        return new UserResource(auth()->user()->load('currentPositionAssignment.position.department'));
    }
}
```

**Note on rate limiting:** Add throttle middleware to login route: `Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')`

---

### 11. Routes

**File:** `routes/api/v1/iam.php`

```php
<?php

use App\Http\Middleware\RequireCapability;
use App\Modules\Iam\Controllers\AuthController;
use App\Modules\Iam\Controllers\CapabilityController;
use App\Modules\Iam\Controllers\DelegationController;
use App\Modules\Iam\Controllers\MonitoringScopeGrantController;
use App\Modules\Iam\Controllers\PositionCapabilityGrantController;
use App\Modules\Iam\Controllers\PositionAssignmentController;
use App\Modules\Iam\Controllers\UserCapabilityGrantController;
use App\Modules\Iam\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('iam')->group(function () {

    // Auth (no auth required for login)
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Authenticated routes
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Users
        Route::middleware(['capability:iam.manage_users'])->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
        });

        // Self-service (user can update own profile)
        Route::get('profile', [UserController::class, 'profile']);
        Route::put('profile', [UserController::class, 'updateProfile']);

        // Position Assignments
        Route::middleware(['capability:iam.manage_positions'])->group(function () {
            Route::post('users/{user}/positions', [PositionAssignmentController::class, 'assign']);
            Route::post('users/{user}/positions/{assignment}/end', [PositionAssignmentController::class, 'end']);
            Route::post('users/{user}/positions/{assignment}/set-primary', [PositionAssignmentController::class, 'setPrimary']);
        });

        // Capabilities (read-only catalog for all authenticated users)
        Route::get('capabilities', [CapabilityController::class, 'index']);
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('capabilities/{capability}', [CapabilityController::class, 'show']);
            Route::put('capabilities/{capability}', [CapabilityController::class, 'update']);
        });

        // Position Capability Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('positions/{position}/capabilities', [PositionCapabilityGrantController::class, 'index']);
            Route::post('positions/{position}/capabilities', [PositionCapabilityGrantController::class, 'grant']);
            Route::post('position-capability-grants/{grant}/revoke', [PositionCapabilityGrantController::class, 'revoke']);
        });

        // User Capability Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('users/{user}/capabilities', [UserCapabilityGrantController::class, 'index']);
            Route::post('users/{user}/capabilities', [UserCapabilityGrantController::class, 'grant']);
            Route::post('user-capability-grants/{grant}/revoke', [UserCapabilityGrantController::class, 'revoke']);
        });

        // Monitoring Scope Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('users/{user}/monitoring-scopes', [MonitoringScopeGrantController::class, 'index']);
            Route::post('users/{user}/monitoring-scopes', [MonitoringScopeGrantController::class, 'grant']);
            Route::post('monitoring-scope-grants/{grant}/revoke', [MonitoringScopeGrantController::class, 'revoke']);
        });

        // Delegations
        Route::middleware(['capability:iam.manage_users'])->group(function () {
            Route::get('delegations', [DelegationController::class, 'index']);
            Route::post('delegations', [DelegationController::class, 'store']);
            Route::get('delegations/{delegation}', [DelegationController::class, 'show']);
            Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);
        });

        // Out-of-office (self or admin)
        Route::post('users/{user}/out-of-office', [UserController::class, 'markOutOfOffice']);
        Route::post('users/{user}/back-in-office', [UserController::class, 'markBackInOffice']);
    });
});
```

**Register in** `routes/tenant.php`:

```php
require __DIR__.'/api/v1/iam.php';
```

---

### 12. Domain Events

All events in `App\Modules\Iam\Events`. Each implements `ShouldDispatchAfterCommit`:

- `UserCreated` — `$user`
- `UserDeactivated` — `$user`
- `UserReactivated` — `$user`
- `PositionAssigned` — `$assignment`
- `PositionEnded` — `$assignment`
- `CapabilityGranted` — `$grant, string $source` (source = 'position' or 'user')
- `CapabilityRevoked` — `$grant, string $source`
- `MonitoringScopeGranted` — `$grant`
- `MonitoringScopeRevoked` — `$grant`
- `DelegationCreated` — `$delegation`
- `DelegationRevoked` — `$delegation`
- `UserMarkedOutOfOffice` — `$user`
- `UserMarkedBackInOffice` — `$user`

Pattern follows Organization module exactly:

```php
<?php

namespace App\Modules\Iam\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\ShouldDispatchAfterCommit;

class UserCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
```

---

### 13. Exceptions

All in `App\Modules\Iam\Exceptions\`. Register each in `bootstrap/app.php` with `->renderable()` returning `response()->json(['message' => $e->getMessage()], 422)`.

```php
// UserAlreadyActiveException
class UserAlreadyActiveException extends Exception
{
    public function __construct()
    {
        parent::__construct('User is already active.');
    }
}

// UserAlreadyDeactivatedException
class UserAlreadyDeactivatedException extends Exception
{
    public function __construct()
    {
        parent::__construct('User is already deactivated.');
    }
}

// CannotDelegateToSelfException
class CannotDelegateToSelfException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delegate authority to yourself.');
    }
}

// PrimaryPositionAlreadyAssignedException
class PrimaryPositionAlreadyAssignedException extends Exception
{
    public function __construct()
    {
        parent::__construct('User already has an active primary position assignment. End the current one first.');
    }
}

// CannotRevokeSystemCapabilityKeyException
class CannotRevokeSystemCapabilityKeyException extends Exception
{
    public function __construct()
    {
        parent::__construct('System-defined capability keys cannot be modified.');
    }
}

// DuplicateGrantException
class DuplicateGrantException extends Exception
{
    public function __construct(string $type = 'grant')
    {
        parent::__construct("An active {$type} with these parameters already exists.");
    }
}
```

---

### 14. Form Requests — Key Validation Rules

#### LoginRequest

```php
public function rules(): array
{
    return [
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ];
}
```

#### StoreUserRequest

```php
public function rules(): array
{
    return [
        'name_ar' => ['required', 'string', 'max:255'],
        'name_en' => ['nullable', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:8'],
        'mobile' => ['nullable', 'string', 'max:30'],
        'employee_id' => ['nullable', 'string', 'max:50', 'unique:users,employee_id'],
        'account_type' => ['required', 'integer', 'in:1,2,3'],
        'preferred_language' => ['nullable', 'integer', 'in:1,2'],
    ];
}
```

**NOTE:** `account_type` validation excludes `4` (platform_admin). Platform admins authenticate via central DB, not tenant DB.

#### GrantPositionCapabilityRequest

```php
public function rules(): array
{
    return [
        'capability_id' => ['required', 'exists:capabilities,public_id'],
        'scope_type' => ['required', 'integer', 'in:1,2,3,4,5'],
        'scope_department_id' => [
            'required_if:scope_type,3,4',
            'nullable',
            'exists:departments,public_id',
        ],
    ];
}
```

**Key validation:** When `scope_type` is 3 (specific_department) or 4 (department_tree), `scope_department_id` is required. Otherwise, it must be null.

#### GrantUserCapabilityRequest

```php
public function rules(): array
{
    return [
        'capability_id' => ['required', 'exists:capabilities,public_id'],
        'scope_type' => ['required', 'integer', 'in:1,2,3,4,5,6'],
        'scope_department_id' => [
            'required_if:scope_type,3,4',
            'nullable',
            'exists:departments,public_id',
        ],
        'reason' => ['required', 'string', 'max:1000'],
    ];
}
```

**NOTE:** `reason` is ALWAYS required for user-level grants (audit-sensitive).

#### StoreDelegationRequest

```php
public function rules(): array
{
    return [
        'delegate_user_id' => ['required', 'exists:users,public_id'],
        'starts_at' => ['required', 'date', 'after:now'],
        'ends_at' => ['required', 'date', 'after:starts_at'],
        'scope_type' => ['required', 'integer', 'in:1,2,3,4'],
        'blueprint_category_id' => ['nullable', 'integer'],
        'stage_type_id' => ['nullable', 'integer'],
    ];
}
```

---

### 15. API Resources — Key Response Shapes

#### AuthTokenResource

```php
public function toArray(Request $request): array
{
    return [
        'user' => [
            'public_id' => $this->resource->public_id,
            'name_ar' => $this->resource->name_ar,
            'name_en' => $this->resource->name_en ?? $this->resource->name_ar,
            'email' => $this->resource->email,
            'account_type' => $this->resource->account_type->value,
        ],
        'token' => $this->token,
    ];
}
```

Construct in controller: `return new AuthTokenResource($user, $plainTextToken);` — use a custom constructor that accepts both.

#### UserResource

Returns public_id, name_ar, name_en, email, mobile, employee_id, account_type, preferred_language, is_active, is_out_of_office. Never expose `password`, `remember_token`, or internal `id`.

#### UserDetailResource (for show/profile)

Same as UserResource plus `current_position` (nested PositionAssignmentResource with position + department) and `effective_capabilities` (list of capability keys from IamPolicy::getEffectiveCapabilities).

#### PositionAssignmentResource

```php
return [
    'public_id' => $this->public_id, // Only if model has public_id; else omit
    'position' => [
        'public_id' => $this->position->public_id,
        'title_ar' => $this->position->title_ar,
        'title_en' => $this->position->title_en ?? $this->position->title_ar,
        'department' => [
            'public_id' => $this->position->department->public_id,
            'name_ar' => $this->position->department->name_ar,
        ],
        'authority_grade' => [
            'public_id' => $this->position->authorityGrade->public_id,
            'rank' => $this->position->authorityGrade->rank,
            'name_ar' => $this->position->authorityGrade->name_ar,
        ],
    ],
    'started_at' => $this->started_at?->toIso8601String(),
    'ended_at' => $this->ended_at?->toIso8601String(),
    'is_primary' => $this->is_primary,
];
```

#### Grant Resources (PositionCapabilityGrantResource, UserCapabilityGrantResource, MonitoringScopeGrantResource)

All return: `id` (these are admin-facing, not route-bound, so internal `id` is acceptable for admin operations), `capability` (or `scope_type` + department), `granted_by` (user public_id), `granted_at`, `revoked_at`. NOTE: For user grants, also include `reason`.

#### DelegationResource

Returns: `public_id`, `delegator` (user public_id + name), `delegate` (user public_id + name), `starts_at`, `ends_at`, `scope_type`, `is_active`.

---

### 16. UserFactory Updates

**File:** `database/factories/UserFactory.php`

Update the existing factory to include new fields:

```php
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'public_id' => null, // auto-generated by HasPublicId
            'name_ar' => fake()->name(),
            'name_en' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'mobile' => fake()->optional()->phoneNumber(),
            'employee_id' => fake()->optional()->unique()->numerify('EMP#####'),
            'account_type' => AccountType::INTERNAL_USER,
            'preferred_language' => 1,
            'is_active' => true,
            'is_out_of_office' => false,
            'email_verified_at' => now(),
        ];
    }
}
```

Also add states for `tenantAdmin()` and `externalAuditor()`:

```php
public function tenantAdmin(): static
{
    return $this->state(['account_type' => AccountType::TENANT_ADMIN]);
}

public function externalAuditor(): static
{
    return $this->state(['account_type' => AccountType::EXTERNAL_AUDITOR]);
}
```

---

### 17. Tests — Minimum Required

Each test file uses the Pest + RefreshDatabase pattern established in Spec 002.

**CRITICAL:** All IAM tests must run in a tenant context. Use the tenancy setup pattern from existing Organization tests. The `CapabilitySeeder` must run before tests that check capabilities.

#### AuthenticationTest.php

```pest
it('can login with valid credentials', function () {
    // Create a user in tenant context
    $user = User::factory()->create(['password' => bcrypt('password')]);
    
    $response = $this->postJson('/v1/iam/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);
    
    $response->assertOk()
        ->assertJsonStructure(['user' => ['public_id', 'name_ar', 'email'], 'token']);
});

it('cannot login with invalid password', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    
    $response = $this->postJson('/v1/iam/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);
    
    $response->assertStatus(422);
});
```

#### IamPolicyTest.php

```pest
it('grants access when user has capability via position grant', function () {
    $user = User::factory()->create();
    $position = Position::factory()->create();
    $capability = Capability::where('key', 'task.view.organization')->first();
    
    // Assign position to user
    UserPositionAssignment::create([
        'user_id' => $user->id,
        'position_id' => $position->id,
        'started_at' => now(),
        'is_primary' => true,
    ]);
    
    // Grant capability to position
    PositionCapabilityGrant::create([
        'position_id' => $position->id,
        'capability_id' => $capability->id,
        'scope_type' => ScopeType::TENANT->value,
        'granted_by_user_id' => $adminUser->id,
        'granted_at' => now(),
    ]);
    
    $policy = app(IamPolicy::class);
    expect($policy->check($user, 'task.view.organization'))->toBeTrue();
});

it('denies access when user lacks capability', function () {
    $user = User::factory()->create();
    
    $policy = app(IamPolicy::class);
    expect($policy->check($user, 'task.view.organization'))->toBeFalse();
});

it('resolves department_tree scope correctly', function () {
    // Create department tree: Parent -> Child -> Grandchild
    $parent = Department::factory()->create();
    $child = Department::factory()->create(['parent_department_id' => $parent->id]);
    $grandchild = Department::factory()->create(['parent_department_id' => $child->id]);
    
    // ... setup user with position grant scoped to $parent with DEPARTMENT_TREE
    // ... assert that check passes for capabilities on $grandchild
});
```

#### PositionAssignmentTest.php

```pest
it('allows only one primary position per user', function () {
    $user = User::factory()->create();
    $dept = Department::factory()->create();
    $grade = AuthorityGrade::factory()->create(['rank' => 1]);
    $pos1 = Position::factory()->create(['department_id' => $dept->id, 'authority_grade_id' => $grade->id]);
    $pos2 = Position::factory()->create(['department_id' => $dept->id, 'authority_grade_id' => $grade->id]);
    
    // Assign first position as primary
    $assignment1 = app(PositionAssignmentService::class)->assign($user, $pos1);
    expect($assignment1->is_primary)->toBeTrue();
    
    // Assign second position as primary — should end the previous primary
    $assignment2 = app(PositionAssignmentService::class)->assign($user, $pos2);
    expect($assignment2->is_primary)->toBeTrue();
    expect($assignment1->fresh()->is_primary)->toBeFalse();
});
```

#### DelegationTest.php

```pest
it('prevents self-delegation', function () {
    $user = User::factory()->create();
    
    $response = $this->postJson("/v1/iam/delegations", [
        'delegate_user_id' => $user->public_id,
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(7)->toIso8601String(),
        'scope_type' => 1,
    ]);
    
    $response->assertStatus(422);
});
```

---

### 18. Exception Registration in bootstrap/app.php

Add all IAM exceptions to the `withExceptions` callback in `bootstrap/app.php`:

```php
use App\Modules\Iam\Exceptions\CannotDelegateToSelfException;
use App\Modules\Iam\Exceptions\CannotRevokeSystemCapabilityKeyException;
use App\Modules\Iam\Exceptions\DuplicateGrantException;
use App\Modules\Iam\Exceptions\PrimaryPositionAlreadyAssignedException;
use App\Modules\Iam\Exceptions\UserAlreadyActiveException;
use App\Modules\Iam\Exceptions\UserAlreadyDeactivatedException;

// Inside withExceptions():
$exceptions->renderable(fn(CannotDelegateToSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn(CannotRevokeSystemCapabilityKeyException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn(DuplicateGrantException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn(PrimaryPositionAlreadyAssignedException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn(UserAlreadyActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
$exceptions->renderable(fn(UserAlreadyDeactivatedException $e) => response()->json(['message' => $e->getMessage()], 422));
```

---

## Execution Order

| Step | What | Depends On |
|------|------|------------|
| 1 | Create `HasPublicId` trait; refactor `TenantModel` to use it | None |
| 2 | Create `AccountType`, `ScopeType`, `DelegationScopeType` enums | None |
| 3 | Run all tenant migrations in order (alter_users → capabilities → assignments → grants → delegations) | Step 2 |
| 4 | Update `User` model (add IAM fields, `HasApiTokens`, `HasPublicId`, `SoftDeletes`, relationships) | Steps 1, 3 |
| 5 | Create all IAM models (Capability, UserPositionAssignment, PositionCapabilityGrant, UserCapabilityGrant, MonitoringScopeGrant, Delegation) | Steps 2, 3 |
| 6 | Create `CapabilitySeeder` | Step 5 |
| 7 | Create `IamPolicy` service + register as singleton in AppServiceProvider | Steps 4, 5 |
| 8 | Create `RequireCapability` middleware + register alias in bootstrap/app.php | Step 7 |
| 9 | Create all Form Requests | Steps 2, 5 |
| 10 | Create all API Resources | Steps 4, 5 |
| 11 | Create all Services (AuthService, UserService, PositionAssignmentService, CapabilityService, GrantService, MonitoringScopeService, DelegationService) | Steps 4–8 |
| 12 | Create all Controllers | Steps 9–11 |
| 13 | Create all domain events | None |
| 14 | Create all exceptions + register in bootstrap/app.php | None |
| 15 | Create routes file (`routes/api/v1/iam.php`) + register in tenant.php | Steps 12, 14 |
| 16 | Update Organization routes: replace `RequireTenantAdmin` with `RequireCapability` | Step 8 |
| 17 | Activate `Position.currentOccupant()` relationship + update PositionResource | Step 5 |
| 18 | Update `UserFactory` | Step 4 |
| 19 | Create Pest feature tests | Steps 1–18 |
| 20 | Run Pint formatter | All code |
| 21 | Run test suite | All code |
| 22 | Update `openapi/openapi.json` | Step 15 |

---

## API Contract Summary

| Method | Endpoint | Auth | Capability | Description |
|--------|----------|------|------------|-------------|
| POST | `/api/v1/iam/auth/login` | none | — | Login (rate-limited 5/min) |
| POST | `/api/v1/iam/auth/logout` | sanctum | — | Logout |
| GET | `/api/v1/iam/auth/me` | sanctum | — | Current user profile |
| GET | `/api/v1/iam/users` | sanctum | `iam.manage_users` | List users (paginated, filterable) |
| POST | `/api/v1/iam/users` | sanctum | `iam.manage_users` | Create user |
| GET | `/api/v1/iam/users/{user}` | sanctum | `iam.manage_users` | Show user detail |
| PUT | `/api/v1/iam/users/{user}` | sanctum | `iam.manage_users` | Update user |
| POST | `/api/v1/iam/users/{user}/deactivate` | sanctum | `iam.manage_users` | Soft-delete user |
| POST | `/api/v1/iam/users/{user}/reactivate` | sanctum | `iam.manage_users` | Reactivate user |
| POST | `/api/v1/iam/users/{user}/positions` | sanctum | `iam.manage_positions` | Assign position |
| POST | `/api/v1/iam/users/{user}/positions/{assignment}/end` | sanctum | `iam.manage_positions` | End position assignment |
| POST | `/api/v1/iam/users/{user}/positions/{assignment}/set-primary` | sanctum | `iam.manage_positions` | Set primary position |
| GET | `/api/v1/iam/capabilities` | sanctum | — | List capabilities |
| GET | `/api/v1/iam/capabilities/{capability}` | sanctum | `iam.manage_capabilities` | Show capability |
| PUT | `/api/v1/iam/capabilities/{capability}` | sanctum | `iam.manage_capabilities` | Update capability name/desc |
| GET | `/api/v1/iam/positions/{position}/capabilities` | sanctum | `iam.manage_capabilities` | List position grants |
| POST | `/api/v1/iam/positions/{position}/capabilities` | sanctum | `iam.manage_capabilities` | Grant capability to position |
| POST | `/api/v1/iam/position-capability-grants/{grant}/revoke` | sanctum | `iam.manage_capabilities` | Revoke position grant |
| GET | `/api/v1/iam/users/{user}/capabilities` | sanctum | `iam.manage_capabilities` | List user effective capabilities |
| POST | `/api/v1/iam/users/{user}/capabilities` | sanctum | `iam.manage_capabilities` | Grant capability to user |
| POST | `/api/v1/iam/user-capability-grants/{grant}/revoke` | sanctum | `iam.manage_capabilities` | Revoke user grant |
| GET | `/api/v1/iam/users/{user}/monitoring-scopes` | sanctum | `iam.manage_capabilities` | List monitoring scopes |
| POST | `/api/v1/iam/users/{user}/monitoring-scopes` | sanctum | `iam.manage_capabilities` | Grant monitoring scope |
| POST | `/api/v1/iam/monitoring-scope-grants/{grant}/revoke` | sanctum | `iam.manage_capabilities` | Revoke monitoring scope |
| GET | `/api/v1/iam/delegations` | sanctum | `iam.manage_users` | List delegations |
| POST | `/api/v1/iam/delegations` | sanctum | `iam.manage_users` | Create delegation |
| GET | `/api/v1/iam/delegations/{delegation}` | sanctum | `iam.manage_users` | Show delegation |
| POST | `/api/v1/iam/delegations/{delegation}/revoke` | sanctum | `iam.manage_users` | Revoke delegation |
| POST | `/api/v1/iam/users/{user}/out-of-office` | sanctum | `iam.manage_users` (or self) | Mark OOO |
| POST | `/api/v1/iam/users/{user}/back-in-office` | sanctum | `iam.manage_users` (or self) | Mark back in office |

---

## Do NOT Do This

These are explicit anti-patterns that must be avoided. A cheaper LLM implementing this spec must check this list:

1. **Do NOT make `User` extend `TenantModel`.** It extends `Authenticatable` and uses `HasPublicId` trait directly.
2. **Do NOT use `SoftDeletes` on `Capability`, `PositionCapabilityGrant`, `UserCapabilityGrant`, `MonitoringScopeGrant`, `UserPositionAssignment`, or `Delegation`.** These models use either `revoked_at` (grants) or `is_active` (delegations) for deactivation.
3. **Do NOT add `public_id` to `UserPositionAssignment`, `PositionCapabilityGrant`, `UserCapabilityGrant`, or `MonitoringScopeGrant`.** These are join/history tables; they use internal `id` only. `Delegation` DOES have `public_id` because it has its own CRUD endpoints.
4. **Do NOT hard-delete grant rows.** Always set `revoked_at = now()` to soft-revoke. This preserves audit trail.
5. **Do NOT add FK constraints for `blueprint_category_id` or `stage_type_id` yet.** These tables don't exist until Specs 004 and 006.
6. **Do NOT allow `account_type = 4` (platform_admin) in tenant DB user creation.** Platform admins are in the central DB only.
7. **Do NOT forget to seed capabilities.** The `CapabilitySeeder` must run during tenant provisioning.
8. **Do NOT forget to resolve `public_id` to internal `id` in service methods.** Form requests validate `public_id` existence; services convert to internal `id` before database operations.
9. **Do NOT forget the `reason` field on user capability grants.** It is always required — no exceptions.
10. **Do NOT leave `RequireTenantAdmin` in Organization routes.** Replace all uses with `RequireCapability:organization.manage`.
11. **Do NOT colocate `User` model in `App\Modules\Iam\Models`.** It stays in `App\Models` because Laravel expects it there for auth.
12. **Do NOT forget `HasApiTokens` trait on `User`.** Without it, Sanctum token auth won't work.
13. **Do NOT forget that `User.name` is being renamed to `User.name_en`.** Update `UserFactory`, all references, and the migration.
14. **Do NOT use `tenant_id` in any tenant DB table.** Physical isolation means no `tenant_id` columns ever.

---

## What to Test Manually

1. **Login with valid credentials:** Create a user via POST `/v1/iam/users`, then login via POST `/v1/iam/auth/login` — verify you get a token and user object back.
2. **Login with invalid password:** Try login with wrong password — expect 422 with generic error message (no user enumeration).
3. **Login as deactivated user:** Create a user, deactivate them, then try to login — expect 422 "inactive account" error.
4. **Bilingual fallback:** Create a user with `name_ar` only (no `name_en`) — verify the response includes `name_en` filled with the Arabic value.
5. **User CRUD cycle:** Create a user → update their mobile → deactivate → reactivate → verify each state change returns the correct `is_active` and `deleted_at` values.
6. **Single primary position per user:** Assign position A as primary to a user, then assign position B as primary — verify position A's `is_primary` is now `false` and position B's is `true`.
7. **Position currentOccupant:** After assigning a user to a position, GET that position — verify `current_occupant` includes the user's `public_id` and Arabic name.
8. **Capability seeding:** After running `CapabilitySeeder`, GET `/v1/iam/capabilities` — verify all 25 system-defined capabilities are present with correct keys.
9. **Capability update protection:** Try to update a system-defined capability's `key` field — verify it's ignored (not changed). Try to update `name_ar` — verify it succeeds.
10. **Position capability grant flow:** Grant `task.view.organization` to a position with `tenant` scope → assign that position to a user → call `IamPolicy::check($user, 'task.view.organization')` — expect `true`. Revoke the grant → call again — expect `false`.
11. **User capability grant with reason:** Grant `task.view.department_touched` directly to a user with `scope_type=2` (own_department) and a mandatory `reason` — verify the grant is created. Try without `reason` — expect 422.
12. **Duplicate grant prevention:** Grant the same capability to the same position twice — expect `DuplicateGrantException` (422).
13. **Department tree scope resolution:** Create departments Parent → Child → Grandchild. Grant a capability to a position with `scope_type=4` (department_tree) scoped to Parent. Assign that position to a user whose department is Grandchild. Call `IamPolicy::check($user, 'task.view.department_touched', ScopeType::OWN_DEPARTMENT, $grandchild->id)` — expect `true`.
14. **Monitoring scope grant:** Grant a monitoring scope to a user for department X — verify the grant appears in the list. Revoke it — verify `revoked_at` is set and the grant no longer appears in active scopes.
15. **Delegation self-prevention:** Try to create a delegation where `delegate_user_id` equals the authenticated user — expect 422 `CannotDelegateToSelfException`.
16. **Delegation revocation:** Create a delegation with future dates — verify it's active. Revoke it — verify `is_active` is `false`.
17. **Out-of-office toggle:** Mark a user as OOO with a delegate — verify `is_out_of_office=true` and `out_of_office_delegate_user_id` is set. Mark them back — verify both are cleared. Verify a non-admin user can mark themselves OOO but not another user.
18. **RequireCapability middleware:** Create an `internal_user` without any capabilities. Try to access POST `/v1/iam/users` — expect 403. Try as `tenant_admin` (account_type=2) — expect 200 (admin bypass).
19. **Organization routes with new middleware:** Verify that POST `/v1/organization/departments` now requires `organization.manage` capability instead of the old `RequireTenantAdmin` middleware.
20. **Tenant isolation:** Create users and capabilities in two different tenants — verify no cross-tenant data leakage in any endpoint.
11. **Do NOT colocate `User` model in `App\Modules\Iam\Models`.** It stays in `App\Models` because Laravel expects it there for auth.
12. **Do NOT forget `HasApiTokens` trait on `User`.** Without it, Sanctum token auth won't work.
13. **Do NOT forget that `User.name` is being renamed to `User.name_en`.** Update `UserFactory`, all references, and the migration.
14. **Do NOT use `tenant_id` in any tenant DB table.** Physical isolation means no `tenant_id` columns ever.
