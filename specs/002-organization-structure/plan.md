# Implementation Plan: 002 Organization Structure

> **Spec:** `specs/002-organization-structure/spec.md`
> **Status:** `approved`
> **Branch:** `feat/002-organization-structure` from `main`

---

## Open Questions Resolved

1. **Authority grades deactivation:** Keep grades permanent for MVP. No `is_active` column. If a grade is no longer used, positions referencing it can be deactivated. A new grade can be created to supersede. Deactivation can be added in V2 if tenants request it.
2. **Department deactivation cascade:** Explicit flag required. Endpoint accepts `cascade_to_children: bool` (default `false`). Accidental mass-deactivation is too dangerous to default to cascade.
3. **Calendar timezone:** Each calendar has its own `timezone` column (per ERD). Service factory defaults to tenant timezone from `central.tenants.timezone` on creation if not provided.
4. **Position transfer history:** In-place `department_id` update with an audit event. Full revision history deferred to Spec 015.

---

## Technical Approach

Build the Organization module as the first full module under `app/Modules/Organization/`, following the architecture pattern: thin controllers → Form Requests for validation → Services for business logic → Models with relationships → API Resources for responses. All Organization tables live in the tenant DB (no `tenant_id` columns). Domain events are emitted for later Audit consumption (Spec 015).

All routes are tenant-scoped, requiring the `X-Tenant` header middleware chain established in Spec 001.

### Key Decisions

- **Module structure:** First module to scaffold `app/Modules/Organization/` with sub-directories for Controllers, Services, Models, Requests, Resources, Events, Exceptions. Sets the pattern for all subsequent modules.
- **Adjacency list for departments:** Use `parent_department_id` self-referencing FK. CTE (Common Table Expression) queries for tree operations. No nested sets — too complex for MVP, adjacency + recursive CTE is sufficient for 5-level hierarchies.
- **UUID v7 via `Str::uuid7()`:** Use Laravel's built-in `Str::uuid7()` for `public_id` generation (available in Laravel 13). The existing `TenantModel` uses `Str::uuid()` (v4) — we'll update the base model to use `uuid7()` and note this in a separate refactor task.
- **Route model binding by `public_id`:** Override `getRouteKeyName()` on all tenant models to return `'public_id'`.
- **Bilingual fallback:** Service layer sets `name_en = name_ar` when `name_en` is null/empty before persisting. Validation requires `name_ar` only.
- **Authorization placeholder:** Until IAM (Spec 003) provides the ABAC engine, all mutating Organization endpoints check `account_type === tenant_admin` via a simple middleware. Read endpoints are authenticated-only.
- **Domain events:** Simple Laravel events dispatched via `event()`. No listeners yet — just establishing the pattern. Spec 015 will add Audit listeners.

---

## Affected Modules / Files

### New Files

```
app/Modules/Organization/
├── Controllers/
│   ├── DepartmentController.php
│   ├── AuthorityGradeController.php
│   ├── PositionController.php
│   ├── WorkingCalendarController.php
│   └── PublicHolidayController.php
├── Services/
│   ├── DepartmentService.php
│   ├── AuthorityGradeService.php
│   ├── PositionService.php
│   ├── CalendarService.php
│   └── WorkingDayCalculator.php
├── Models/
│   ├── Department.php
│   ├── AuthorityGrade.php
│   ├── Position.php
│   ├── WorkingCalendar.php
│   └── PublicHoliday.php
├── Requests/
│   ├── StoreDepartmentRequest.php
│   ├── UpdateDepartmentRequest.php
│   ├── StoreAuthorityGradeRequest.php
│   ├── UpdateAuthorityGradeRequest.php
│   ├── StorePositionRequest.php
│   ├── UpdatePositionRequest.php
│   ├── TransferPositionRequest.php
│   ├── StoreWorkingCalendarRequest.php
│   ├── UpdateWorkingCalendarRequest.php
│   ├── StorePublicHolidayRequest.php
│   └── UpdatePublicHolidayRequest.php
├── Resources/
│   ├── DepartmentResource.php
│   ├── DepartmentTreeResource.php
│   ├── AuthorityGradeResource.php
│   ├── PositionResource.php
│   ├── WorkingCalendarResource.php
│   └── PublicHolidayResource.php
├── Events/
│   ├── DepartmentCreated.php
│   ├── DepartmentUpdated.php
│   ├── DepartmentDeactivated.php
│   ├── PositionCreated.php
│   ├── PositionTransferred.php
│   ├── PositionDeactivated.php
│   ├── AuthorityGradeCreated.php
│   ├── WorkingCalendarCreated.php
│   └── PublicHolidayCreated.php
└── Exceptions/
    ├── CircularDepartmentReferenceException.php
    ├── CircularReportingLineException.php
    └── DepartmentHasActivePositionsException.php
```

```
database/migrations/tenant/
├── 2026_06_08_000001_create_authority_grades_table.php
├── 2026_06_08_000002_create_departments_table.php
├── 2026_06_08_000003_create_positions_table.php
├── 2026_06_08_000004_create_working_calendars_table.php
└── 2026_06_08_000005_create_public_holidays_table.php
```

```
routes/
└── api/
    └── v1/
        └── organization.php
```

```
tests/Feature/Modules/Organization/
├── DepartmentTest.php
├── AuthorityGradeTest.php
├── PositionTest.php
├── WorkingCalendarTest.php
└── PublicHolidayTest.php
```

### Modified Files

| File | Change |
|------|--------|
| `app/Models/TenantModel.php` | Update `Str::uuid()` → `Str::uuid7()` for UUID v7 |
| `routes/tenant.php` | Include `routes/api/v1/organization.php` |
| `app/Providers/AppServiceProvider.php` | Register module ServiceProviders if using modular providers |
| `openapi/openapi.json` | Update after all endpoints are implemented |

---

## Implementation Notes

### 1. Update TenantModel base — UUID v7

**File:** `app/Models/TenantModel.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

abstract class TenantModel extends Model
{
    use SoftDeletes;

    protected static function booted()
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

**Changed:** `Str::uuid()` → `Str::uuid7()`; added `getRouteKeyName()` override for `public_id` route model binding.

---

### 2. Migrations

All migrations go in `database/migrations/tenant/`. Create order follows the FK dependency chain from the ERD (Level 0 → Level 1 → …).

#### `2026_06_08_000001_create_authority_grades_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_grades', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->smallInteger('rank')->unsigned()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index('rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_grades');
    }
};
```

#### `2026_06_08_000002_create_departments_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('parent_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_department_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
```

#### `2026_06_08_000003_create_positions_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('department_id')->constrained('departments');
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->foreignId('reports_to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('authority_grade_id')->constrained('authority_grades');
            $table->boolean('is_department_head')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('department_id');
            $table->index('authority_grade_id');
            $table->index('is_active');
            $table->index('is_department_head');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
```

#### `2026_06_08_000004_create_working_calendars_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('working_days', 50);
            $table->time('working_hours_start');
            $table->time('working_hours_end');
            $table->string('timezone', 100)->default('Asia/Riyadh');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_calendars');
    }
};
```

#### `2026_06_08_000005_create_public_holidays_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('working_calendar_id')->constrained('working_calendars')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->date('holiday_date');
            $table->boolean('is_recurring')->default(false);
            $table->timestamps();

            $table->unique(['working_calendar_id', 'holiday_date']);
            $table->index('holiday_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
```

---

### 3. Models

All Organization models extend `TenantModel` and live in `App\Modules\Organization\Models`.

#### `Department.php`

```php
<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_department_id', 'name_ar', 'name_en', 'is_active'])]
#[Hidden([])]
class Department extends TenantModel
{
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_department_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function activePositions(): HasMany
    {
        return $this->hasMany(Position::class)->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

#### `AuthorityGrade.php`

```php
<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['rank', 'name_ar', 'name_en', 'description'])]
class AuthorityGrade extends TenantModel
{
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    protected function casts(): array
    {
        return [];
    }
}
```

**Note:** Authority grades do NOT use `SoftDeletes`. No `deleted_at` column. Grades are permanent for MVP — supersede by creating a new grade.

#### `Position.php`

```php
<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['department_id', 'title_ar', 'title_en', 'reports_to_position_id', 'authority_grade_id', 'is_department_head', 'is_active'])]
class Position extends TenantModel
{
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_position_id');
    }

    public function authorityGrade(): BelongsTo
    {
        return $this->belongsTo(AuthorityGrade::class);
    }

    public function currentOccupant(): HasOne
    {
        return $this->hasOne(\App\Modules\Iam\Models\UserPositionAssignment::class, 'position_id')
            ->where('is_primary', true)
            ->whereNull('ended_at');
    }

    protected function casts(): array
    {
        return [
            'is_department_head' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}
```

**Note:** `currentOccupant()` references `UserPositionAssignment` from the IAM module (Spec 003). For now, this relationship will be commented out with a `// TODO: Uncomment when IAM module is built (Spec 003)` marker, and the PositionResource will return `null` for `current_occupant` until then.

#### `WorkingCalendar.php`

```php
<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_ar', 'name_en', 'working_days', 'working_hours_start', 'working_hours_end', 'timezone', 'is_default'])]
class WorkingCalendar extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(PublicHoliday::class);
    }
}
```

**Note:** No `public_id` on `working_calendars` or `public_holidays` per ERD — these are tenant-internal configuration, not API-exposed entities that need obfuscation. Wait, actually the spec says "All responses use API Resources with `public_id` only — never expose internal `id`". So we need `public_id` on both. <!-- TODO: verify — the ERD does not show `public_id` on `working_calendars` or `public_holidays`, but the API contract rule says never expose internal `id`. Add `public_id` to both tables for consistency. -->

Decision: Add `public_id` to `working_calendars` only (it's an API-exposed CRUD resource). `public_holidays` is a child resource always accessed through a calendar, so it gets `public_id` too for consistency in update/delete operations. Both already included in migration above.

#### `PublicHoliday.php`

```php
<?php

namespace App\Modules\Organization\Models;

use App\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['working_calendar_id', 'name_ar', 'name_en', 'holiday_date', 'is_recurring'])]
class PublicHoliday extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_recurring' => 'boolean',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }
}
```

**Note:** `PublicHoliday` does NOT use `SoftDeletes` per ERD (no `deleted_at`). Hard delete is acceptable for holidays.

---

### 4. Form Requests

All Form Requests live in `App\Modules\Organization\Requests`.

#### `StoreDepartmentRequest.php`

```php
<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware until IAM module
    }

    public function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_department_id' => ['nullable', 'uuid', 'exists:departments,public_id'],
        ];
    }
}
```

`UpdateDepartmentRequest` is identical except `name_ar` and `name_en` are `sometimes|required|string|max:255` and `sometimes|nullable|string|max:255`.

#### `StorePositionRequest.php`

```php
<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,public_id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'reports_to_position_id' => ['nullable', 'uuid', 'exists:positions,public_id'],
            'authority_grade_id' => ['required', 'exists:authority_grades,public_id'],
            'is_department_head' => ['boolean'],
        ];
    }
}
```

#### `TransferPositionRequest.php`

```php
<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'exists:departments,public_id'],
        ];
    }
}
```

#### `StoreWorkingCalendarRequest.php`

```php
<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkingCalendarRequest extends FormRequest
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
            'working_days' => ['required', 'string', 'max:50', 'regex:/^[0-6](,[0-6])*$/'],
            'working_hours_start' => ['required', 'date_format:H:i'],
            'working_hours_end' => ['required', 'date_format:H:i', 'after:working_hours_start'],
            'timezone' => ['nullable', 'string', 'max:100', 'timezone'],
            'is_default' => ['boolean'],
        ];
    }
}
```

#### `StorePublicHolidayRequest.php`

```php
<?php

namespace App\Modules\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicHolidayRequest extends FormRequest
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
            'holiday_date' => ['required', 'date'],
            'is_recurring' => ['boolean'],
        ];
    }
}
```

---

### 5. API Resources

All resources live in `App\Modules\Organization\Resources`. Every resource exposes `public_id` and omits internal `id`.

#### `DepartmentResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'parent_department_id' => $this->when($this->parent_department_id, $this->parentDepartment?->public_id),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

#### `DepartmentTreeResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentTreeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'is_active' => $this->is_active,
            'children' => DepartmentTreeResource::collection($this->whenLoaded('children')),
        ];
    }
}
```

#### `PositionResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'department' => [
                'public_id' => $this->department?->public_id,
                'name_ar' => $this->department?->name_ar,
            ],
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en ?? $this->title_ar,
            'reports_to_position_id' => $this->when($this->reports_to_position_id, $this->reportsTo?->public_id),
            'authority_grade' => [
                'public_id' => $this->authorityGrade?->public_id,
                'rank' => $this->authorityGrade?->rank,
                'name_ar' => $this->authorityGrade?->name_ar,
            ],
            'is_department_head' => $this->is_department_head,
            'is_active' => $this->is_active,
            // 'current_occupant' => new UserResource($this->whenLoaded('currentOccupant')), // TODO: Spec 003
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

#### `AuthorityGradeResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorityGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'rank' => $this->rank,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

#### `WorkingCalendarResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkingCalendarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'working_days' => $this->working_days,
            'working_hours_start' => $this->working_hours_start,
            'working_hours_end' => $this->working_hours_end,
            'timezone' => $this->timezone,
            'is_default' => $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

#### `PublicHolidayResource.php`

```php
<?php

namespace App\Modules\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en ?? $this->name_ar,
            'holiday_date' => $this->holiday_date?->toDateString(),
            'is_recurring' => $this->is_recurring,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

---

### 6. Domain Events

All events live in `App\Modules\Organization\Events`. Each event implements `ShouldDispatchAfterCommit` to ensure events only fire after the DB transaction completes.

```php
<?php

namespace App\Modules\Organization\Events;

use App\Modules\Organization\Models\Department;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\ShouldDispatchAfterCommit;

class DepartmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Department $department) {}
}
```

Follow this pattern for: `DepartmentUpdated`, `DepartmentDeactivated`, `PositionCreated`, `PositionUpdated`, `PositionTransferred`, `PositionDeactivated`, `AuthorityGradeCreated`, `AuthorityGradeUpdated`, `WorkingCalendarCreated`, `WorkingCalendarUpdated`, `PublicHolidayCreated`, `PublicHolidayDeleted`.

No listeners are registered in this spec — the events are dispatched for Spec 015 (Audit) to consume later.

---

### 7. Exceptions

```php
<?php

namespace App\Modules\Organization\Exceptions;

use Exception;

class CircularDepartmentReferenceException extends Exception
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a department cannot be its own ancestor.');
    }
}

class CircularReportingLineException extends Exception
{
    public function __construct()
    {
        parent::__construct('Circular reference detected: a position cannot report to itself or its descendants.');
    }
}

class DepartmentHasActivePositionsException extends Exception
{
    public function __construct()
    {
        parent::__construct('Cannot delete a department that has active positions.');
    }
}
```

Register these in `bootstrap/app.php` or a dedicated exception handler to return 422 JSON responses:

```php
// In bootstrap/app.php -> withExceptions()
->renderable(function (CircularDepartmentReferenceException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
})
->renderable(function (CircularReportingLineException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
})
->renderable(function (DepartmentHasActivePositionsException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
});
```

---

### 8. Services

All services live in `App\Modules\Organization\Services`. Business logic goes here — controllers delegate to services.

#### `DepartmentService.php`

Key methods:

```php
class DepartmentService
{
    public function create(array $data): Department
    {
        // Resolve parent public_id to internal id if provided
        // Set name_en = name_ar if name_en is empty
        // Create department
        // event(new DepartmentCreated($department))
        // return $department
    }

    public function update(Department $department, array $data): Department
    {
        // Check circular reference if parent_department_id changed
        // Update department
        // event(new DepartmentUpdated($department))
        // return $department
    }

    public function deactivate(Department $department, bool $cascadeToChildren = false): Department
    {
        // Set is_active = false
        // If cascadeToChildren, deactivate all children recursively
        // event(new DepartmentDeactivated($department))
        // return $department
    }

    public function reactivate(Department $department): Department
    {
        // Set is_active = true
        // return $department
    }

    public function delete(Department $department): void
    {
        // Throw DepartmentHasActivePositionsException if active positions exist
        // Soft delete (triggers model SoftDeletes)
        // Also deactivate all active positions belonging to this department
    }

    public function getTree(): Collection
    {
        // Eager-load children recursively (up to 5 levels)
        // return Department::with('children.children.children.children.children')
        //     ->whereNull('parent_department_id')->get();
    }

    private function wouldCreateCircularReference(Department $department, ?string $newParentPublicId): bool
    {
        // Walk up the ancestor chain from new parent
        // If we encounter $department's id, it's circular
        // return true/false
    }
}
```

#### `PositionService.php`

Key methods:

```php
class PositionService
{
    public function create(array $data): Position
    {
        // Resolve department_id, authority_grade_id, reports_to_position_id from public_id
        // Set name_en fallback
        // If is_department_head, unset any existing head for same department
        // Create position
        // event(new PositionCreated($position))
    }

    public function update(Position $position, array $data): Position
    {
        // Check circular reporting line if reports_to_position_id changed
        // Update position
        // event(new PositionUpdated($position))
    }

    public function transfer(Position $position, string $newDepartmentPublicId): Position
    {
        // Update department_id
        // event(new PositionTransferred($position))
    }

    public function deactivate(Position $position): Position
    {
        // Set is_active = false
        // event(new PositionDeactivated($position))
    }

    public function reactivate(Position $position): Position
    {
        // Set is_active = true
        // return $position
    }

    private function wouldCreateCircularReportingLine(Position $position, ?string $newReportsToPublicId): bool
    {
        // Walk up the reporting chain from new reports_to position
        // If we encounter $position's id, it's circular
    }
}
```

#### `CalendarService.php`

Key methods:

```php
class CalendarService
{
    public function create(array $data): WorkingCalendar
    {
        // If is_default, unset previous default
        // Create calendar
        // event(new WorkingCalendarCreated($calendar))
    }

    public function update(WorkingCalendar $calendar, array $data): WorkingCalendar
    {
        // If is_default being set, unset previous default
        // Update calendar
        // event(new WorkingCalendarUpdated($calendar))
    }

    public function delete(WorkingCalendar $calendar): void
    {
        // Throw if is_default (cannot delete default calendar)
        // Delete calendar (cascades to holidays)
    }
}
```

#### `WorkingDayCalculator.php`

This service is consumed by the SLA module (Spec 007). It provides pure calculation methods with no DB writes.

```php
<?php

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\WorkingCalendar;
use Carbon\Carbon;

class WorkingDayCalculator
{
    public function isWorkingDay(WorkingCalendar $calendar, Carbon $date): bool
    {
        // 1. Parse working_days string into array of integers
        // 2. Check if date's day-of-week is in the working_days array
        // 3. Check if date matches any non-recurring holiday
        // 4. Check if date matches any recurring holiday (month-day match)
        // 5. Return false if holiday, true if working day and in working_days
    }

    public function nextWorkingDay(WorkingCalendar $calendar, Carbon $fromDate): Carbon
    {
        $date = $fromDate->copy()->addDay();
        while (! $this->isWorkingDay($calendar, $date)) {
            $date->addDay();
        }
        return $date;
    }

    public function addWorkingDays(WorkingCalendar $calendar, Carbon $fromDate, int $days): Carbon
    {
        $date = $fromDate->copy();
        for ($i = 0; $i < $days; $i++) {
            $date = $this->nextWorkingDay($calendar, $date);
        }
        return $date;
    }

    public function isWorkingTime(WorkingCalendar $calendar, Carbon $datetime): bool
    {
        if (! $this->isWorkingDay($calendar, $datetime)) {
            return false;
        }
        $start = Carbon::createFromTimeString($calendar->working_hours_start);
        $end = Carbon::createFromTimeString($calendar->working_hours_end);
        $time = $datetime->format('H:i:s');
        return $time >= $start->format('H:i:s') && $time <= $end->format('H:i:s');
    }
}
```

**Two test cases:**

1. `isWorkingDay` — Given a calendar with working days `0,1,2,3,4` (Sun-Thu) and a holiday on `2026-06-15` (Sunday, non-recurring): `isWorkingDay($calendar, Carbon::parse('2026-06-14'))` returns `true` (Saturday is not a working day anyway), `isWorkingDay($calendar, Carbon::parse('2026-06-15'))` returns `false` (Sunday would be working but it's a holiday), `isWorkingDay($calendar, Carbon::parse('2026-06-16'))` returns `true` (Monday, working day).

2. `addWorkingDays` — Given a calendar with working days `0,1,2,3,4` and a Friday holiday: `addWorkingDays($calendar, Carbon::parse('2026-06-14'), 3)` returns `2026-06-17` (Sunday+3 working days = Wed, skipping the Friday holiday if any, skipping Fri/Sat non-working days).

---

### 9. Controllers

All controllers are thin: validate (FormRequest) → delegate (Service) → respond (Resource).

#### `DepartmentController.php`

```php
<?php

namespace App\Modules\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Requests\StoreDepartmentRequest;
use App\Modules\Organization\Requests\UpdateDepartmentRequest;
use App\Modules\Organization\Resources\DepartmentResource;
use App\Modules\Organization\Resources\DepartmentTreeResource;
use App\Modules\Organization\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepartmentController extends Controller
{
    public function __construct(
        private DepartmentService $departmentService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Department::query()->with('parent');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('parent_department_id')) {
            $query->where('parent_department_id', 
                Department::where('public_id', $request->input('parent_department_id'))->value('id')
            );
        }

        return DepartmentResource::collection(
            $query->orderBy('name_ar')->paginate($request->integer('per_page', 15))
        );
    }

    public function tree(): AnonymousResourceCollection
    {
        $roots = Department::with('children.children.children.children.children')
            ->whereNull('parent_department_id')
            ->get();

        return DepartmentTreeResource::collection($roots);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create($request->validated());

        return response()->json(
            new DepartmentResource($department->load('parent')),
            201
        );
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json(
            new DepartmentResource($department->load('parent', 'children'))
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->update($department, $request->validated());

        return response()->json(new DepartmentResource($department));
    }

    public function deactivate(Request $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->deactivate(
            $department,
            $request->boolean('cascade_to_children', false)
        );

        return response()->json(new DepartmentResource($department));
    }

    public function reactivate(Department $department): JsonResponse
    {
        $department = $this->departmentService->reactivate($department);

        return response()->json(new DepartmentResource($department));
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->departmentService->delete($department);

        return response()->json(null, 204);
    }
}
```

Other controllers follow the same pattern: inject service, delegate, return resource.

---

### 10. Routes

Create `routes/api/v1/organization.php`:

```php
<?php

use App\Modules\Organization\Controllers\AuthorityGradeController;
use App\Modules\Organization\Controllers\DepartmentController;
use App\Modules\Organization\Controllers\PositionController;
use App\Modules\Organization\Controllers\PublicHolidayController;
use App\Modules\Organization\Controllers\WorkingCalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    // Departments
    Route::prefix('departments')->group(function () {
        Route::get('/', [DepartmentController::class, 'index']);
        Route::get('tree', [DepartmentController::class, 'tree']);
        Route::post('/', [DepartmentController::class, 'store']);
        Route::get('{department}', [DepartmentController::class, 'show']);
        Route::put('{department}', [DepartmentController::class, 'update']);
        Route::post('{department}/deactivate', [DepartmentController::class, 'deactivate']);
        Route::post('{department}/reactivate', [DepartmentController::class, 'reactivate']);
        Route::delete('{department}', [DepartmentController::class, 'destroy']);
    });

    // Authority Grades
    Route::prefix('authority-grades')->group(function () {
        Route::get('/', [AuthorityGradeController::class, 'index']);
        Route::post('/', [AuthorityGradeController::class, 'store']);
        Route::get('{authorityGrade}', [AuthorityGradeController::class, 'show']);
        Route::put('{authorityGrade}', [AuthorityGradeController::class, 'update']);
        Route::delete('{authorityGrade}', [AuthorityGradeController::class, 'destroy']);
    });

    // Positions
    Route::prefix('positions')->group(function () {
        Route::get('/', [PositionController::class, 'index']);
        Route::post('/', [PositionController::class, 'store']);
        Route::get('{position}', [PositionController::class, 'show']);
        Route::put('{position}', [PositionController::class, 'update']);
        Route::post('{position}/transfer', [PositionController::class, 'transfer']);
        Route::post('{position}/deactivate', [PositionController::class, 'deactivate']);
        Route::post('{position}/reactivate', [PositionController::class, 'reactivate']);
        Route::delete('{position}', [PositionController::class, 'destroy']);
    });

    // Working Calendars
    Route::prefix('working-calendars')->group(function () {
        Route::get('/', [WorkingCalendarController::class, 'index']);
        Route::post('/', [WorkingCalendarController::class, 'store']);
        Route::get('{workingCalendar}', [WorkingCalendarController::class, 'show']);
        Route::put('{workingCalendar}', [WorkingCalendarController::class, 'update']);
        Route::delete('{workingCalendar}', [WorkingCalendarController::class, 'destroy']);

        // Public Holidays (nested resource)
        Route::get('{workingCalendar}/holidays', [PublicHolidayController::class, 'index']);
        Route::post('{workingCalendar}/holidays', [PublicHolidayController::class, 'store']);
        Route::get('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'show']);
        Route::put('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'update']);
        Route::delete('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy']);

        // Working day check (utility endpoint)
        Route::get('{workingCalendar}/is-working-day', [WorkingCalendarController::class, 'isWorkingDay']);
    });
});
```

**Note on authorization:** All mutating routes (`POST`, `PUT`, `DELETE`) require `organization.manage` capability. Until the ABAC engine (Spec 003) is built, we add a simple middleware that checks `auth()->user()->account_type === 2` (tenant_admin). Create `app/Http/Middleware/RequireTenantAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (int) $user->account_type !== 2) {
            abort(403, 'This action requires tenant administrator privileges.');
        }

        return $next($request);
    }
}
```

Apply this middleware to mutating routes only. Read routes remain `auth:sanctum` only. This middleware will be replaced by the ABAC policy engine in Spec 003.

---

### 11. Register Routes in `routes/tenant.php`

Add the organization routes file to the tenant route group:

```php
// In routes/tenant.php, inside the existing middleware group:
Route::middleware([
    'api',
    InitializeTenancyByHeader::class,
    CheckTenantStatus::class,
])->prefix('api/v1')->group(function () {
    // ... existing test routes ...

    require __DIR__ . '/api/v1/organization.php';
});
```

---

### 12. Tests

Pest tests in `tests/Feature/Modules/Organization/`. Each test file uses `RefreshDatabase` and initializes tenancy context.

#### `DepartmentTest.php`

```pest
<?php

use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Services\DepartmentService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = tenant(); // Assumes test sets up tenancy context
    // Or manually: tenancy()->initialize(Tenant::factory()->create());
});

it('creates a top-level department', function () {
    $service = app(DepartmentService::class);
    $dept = $service->create(['name_ar' => 'وزارة المالية', 'name_en' => 'Ministry of Finance']);

    expect($dept)
        ->name_ar->toBe('وزارة المالية')
        ->name_en->toBe('Ministry of Finance')
        ->parent_department_id->toBeNull()
        ->is_active->toBeTrue();
});

it('prevents circular department references', function () {
    $service = app(DepartmentService::class);
    $parent = $service->create(['name_ar' => 'أم']);
    $child = $service->create(['name_ar' => 'طفل', 'parent_department_id' => $parent->public_id]);

    // Try to set parent's parent to child
    $service->update($parent, ['parent_department_id' => $child->public_id]);
})->throws(\App\Modules\Organization\Exceptions\CircularDepartmentReferenceException::class);
```

#### `PositionTest.php`

```pest
<?php

use App\Modules\Organization\Models\AuthorityGrade;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\PositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ensures only one department head per department', function () {
    $dept = Department::factory()->create();
    $grade = AuthorityGrade::factory()->create(['rank' => 4]);

    $service = app(PositionService::class);
    $head1 = $service->create([
        'department_id' => $dept->public_id,
        'title_ar' => 'المدير الأول',
        'authority_grade_id' => $grade->public_id,
        'is_department_head' => true,
    ]);
    $head2 = $service->create([
        'department_id' => $dept->public_id,
        'title_ar' => 'المدير الثاني',
        'authority_grade_id' => $grade->public_id,
        'is_department_head' => true,
    ]);

    // head1 should no longer be department head
    expect($head1->fresh()->is_department_head)->toBeFalse();
    expect($head2->fresh()->is_department_head)->toBeTrue();
});
```

#### `WorkingCalendarTest.php`

```pest
<?php

use App\Modules\Organization\Models\WorkingCalendar;
use App\Modules\Organization\Services\CalendarService;
use App\Modules\Organization\Services\WorkingDayCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('toggles default calendar — only one default at a time', function () {
    $service = app(CalendarService::class);
    $cal1 = $service->create([
        'name_ar' => 'التقويم الأول',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
        'is_default' => true,
    ]);
    $cal2 = $service->create([
        'name_ar' => 'التقويم الثاني',
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '09:00',
        'working_hours_end' => '17:00',
        'is_default' => true,
    ]);

    expect($cal1->fresh()->is_default)->toBeFalse();
    expect($cal2->fresh()->is_default)->toBeTrue();
});

it('calculates working days correctly excluding holidays', function () {
    $calendar = WorkingCalendar::factory()->create([
        'working_days' => '0,1,2,3,4',
        'working_hours_start' => '08:00',
        'working_hours_end' => '16:00',
    ]);
    $calendar->holidays()->create([
        'name_ar' => 'العطلة الوطنية',
        'holiday_date' => '2026-06-15',
        'is_recurring' => false,
    ]);

    $calc = app(WorkingDayCalculator::class);

    // 2026-06-15 is a Sunday (working day normally) but is a holiday
    expect($calc->isWorkingDay($calendar, Carbon::parse('2026-06-15')))->toBeFalse();
    expect($calc->isWorkingDay($calendar, Carbon::parse('2026-06-16')))->toBeTrue();
});
```

---

### 13. Model Factories

Create factories for test data in `database/factories/`:

```php
// DepartmentFactory.php
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name_ar' => fake()->unique()->company(),
            'name_en' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}

// AuthorityGradeFactory.php
class AuthorityGradeFactory extends Factory
{
    protected $model = AuthorityGrade::class;

    public function definition(): array
    {
        static $rank = 1;
        return [
            'rank' => $rank++,
            'name_ar' => 'الرتبة ' . $rank,
            'name_en' => 'Grade ' . $rank,
        ];
    }
}

// PositionFactory.php, WorkingCalendarFactory.php — similar pattern
```

**Note:** Factories must run within a tenant context. Use `$tenant->run(callback)` or `tenancy()->initialize($tenant)` before creating records.

---

## Execution Order

| Step | What | Depends On |
|------|------|------------|
| 1 | Update `TenantModel` — `Str::uuid7()` + `getRouteKeyName()` | None |
| 2 | Create `RequireTenantAdmin` middleware | None |
| 3 | Create migrations (authority_grades → departments → positions → working_calendars → public_holidays) | Step 1 |
| 4 | Create all 5 models with relationships, scopes, casts | Step 3 |
| 5 | Create all Form Requests | Step 4 |
| 6 | Create all API Resources | Step 4 |
| 7 | Create domain events | None |
| 8 | Create exceptions + register in exception handler | None |
| 9 | Create all services (DepartmentService, AuthorityGradeService, PositionService, CalendarService, WorkingDayCalculator) | Steps 3–8 |
| 10 | Create all controllers | Steps 5–9 |
| 11 | Create routes file & register in tenant routes | Step 10 |
| 12 | Create model factories | Step 4 |
| 13 | Create Pest feature tests | Steps 3–12 |
| 14 | Run Pint formatter | All code |
| 15 | Run test suite | All code |
| 16 | Update `openapi/openapi.json` | Step 11 |

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/v1/organization/departments` | auth | List departments (paginated, filterable) |
| GET | `/api/v1/organization/departments/tree` | auth | Full department tree |
| POST | `/api/v1/organization/departments` | auth+admin | Create department |
| GET | `/api/v1/organization/departments/{public_id}` | auth | Show department |
| PUT | `/api/v1/organization/departments/{public_id}` | auth+admin | Update department |
| POST | `/api/v1/organization/departments/{public_id}/deactivate` | auth+admin | Deactivate department |
| POST | `/api/v1/organization/departments/{public_id}/reactivate` | auth+admin | Reactivate department |
| DELETE | `/api/v1/organization/departments/{public_id}` | auth+admin | Soft-delete department |
| GET | `/api/v1/organization/authority-grades` | auth | List authority grades |
| POST | `/api/v1/organization/authority-grades` | auth+admin | Create authority grade |
| GET | `/api/v1/organization/authority-grades/{public_id}` | auth | Show authority grade |
| PUT | `/api/v1/organization/authority-grades/{public_id}` | auth+admin | Update authority grade |
| DELETE | `/api/v1/organization/authority-grades/{public_id}` | auth+admin | Delete authority grade (if no active positions) |
| GET | `/api/v1/organization/positions` | auth | List positions (paginated, filterable) |
| POST | `/api/v1/organization/positions` | auth+admin | Create position |
| GET | `/api/v1/organization/positions/{public_id}` | auth | Show position (with current occupant) |
| PUT | `/api/v1/organization/positions/{public_id}` | auth+admin | Update position |
| POST | `/api/v1/organization/positions/{public_id}/transfer` | auth+admin | Transfer position to new dept |
| POST | `/api/v1/organization/positions/{public_id}/deactivate` | auth+admin | Deactivate position |
| POST | `/api/v1/organization/positions/{public_id}/reactivate` | auth+admin | Reactivate position |
| DELETE | `/api/v1/organization/positions/{public_id}` | auth+admin | Soft-delete position |
| GET | `/api/v1/organization/working-calendars` | auth | List calendars |
| POST | `/api/v1/organization/working-calendars` | auth+admin | Create calendar |
| GET | `/api/v1/organization/working-calendars/{public_id}` | auth | Show calendar |
| PUT | `/api/v1/organization/working-calendars/{public_id}` | auth+admin | Update calendar |
| DELETE | `/api/v1/organization/working-calendars/{public_id}` | auth+admin | Delete calendar (not default) |
| GET | `/api/v1/organization/working-calendars/{public_id}/is-working-day?date=YYYY-MM-DD` | auth | Check if date is a working day |
| GET | `/api/v1/organization/working-calendars/{public_id}/holidays` | auth | List holidays for calendar |
| POST | `/api/v1/organization/working-calendars/{public_id}/holidays` | auth+admin | Create holiday |
| GET | `/api/v1/organization/working-calendars/{public_id}/holidays/{public_id}` | auth | Show holiday |
| PUT | `/api/v1/organization/working-calendars/{public_id}/holidays/{public_id}` | auth+admin | Update holiday |
| DELETE | `/api/v1/organization/working-calendars/{public_id}/holidays/{public_id}` | auth+admin | Delete holiday |

**Auth legend:** `auth` = authenticated user; `admin` = `RequireTenantAdmin` middleware (tenant_admin account type). Will be replaced by ABAC capability checks in Spec 003.

---

## Risks & Side Effects

1. **TenantModel UUID v7 change:** Updating `Str::uuid()` → `Str::uuid7()` in `TenantModel` affects all future tenant model `public_id` values. Existing test data with UUID v4 `public_id` values will continue to work (both are valid UUIDs). Existing `Tenant` model in central DB uses its own `booted()` hook — no conflict, but should be updated to v7 for consistency. <!-- TODO: verify — update Tenant model to v7 as well? Low priority, existing v4 UUIDs remain valid. -->

2. **Position `currentOccupant` relationship:** References `UserPositionAssignment` which doesn't exist until Spec 003 (IAM). The relationship will be commented out and the PositionResource will return `null` for `current_occupant`. This is an intentional placeholder.

3. **Circular reference prevention:** The current approach walks ancestors in PHP (loop + DB query per level). For very deep hierarchies (unlikely at <5 levels per spec), a recursive CTE or cached `path` column would be more efficient. Accepted for MVP.

4. **Department tree endpoint:** Loads up to 5 levels of children with nested eager loading. For organizations with hundreds of departments, this could be slow. Acceptable for MVP (~10-30 departments per tenant). Optimize with recursive CTE in V2 if needed.

5. **Working calendar timezone:** Each calendar stores its own `timezone`. The `WorkingDayCalculator` uses `Carbon` which handles timezone conversion natively. Verify that `Carbon::parse($date)->timezone($calendar->timezone)` works correctly for DST transitions.

6. **Module boundary:** Organization models must NOT have Eloquent relationships pointing to IAM, Blueprint, or Task models. Cross-module data access must use service calls or events. The `Position.currentOccupant()` relationship is the one exception documented for Spec 003 — it will be uncommented when IAM is built.

---

## What to Test Manually

1. **Department tree creation:** Create 4 levels of nested departments and verify the tree endpoint returns correct nesting.
2. **Circular reference prevention:** Try setting a department's parent to one of its own descendants — expect 422 error.
3. **Department soft-delete cascade to positions:** Delete a department with active positions — expect 422 error. Deactivate the positions first, then delete — expect success.
4. **Position head-of-department uniqueness:** Create two positions as department head in the same department — verify only the latest is head.
5. **Calendar default toggle:** Create two calendars as default — verify only the second one remains default.
6. **Holiday uniqueness constraint:** Create two holidays on the same date for the same calendar — expect 422 validation error.
7. **Bilingual fallback:** Create a department with `name_ar` only (no `name_en`) — verify the response includes `name_en` filled with the Arabic value.
8. **Tenant isolation:** Create departments in two different tenants — verify no cross-tenant data leakage.
