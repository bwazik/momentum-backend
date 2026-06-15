# Implementation Plan: 011 Search & Discovery

> **Spec:** 011-search-discovery
> **Date:** 2026-06-15
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| Where to emit `TaskViewed`? | **Add `TaskService::findVisible()` and emit `TaskViewed` from it; call it from `TaskController::show()`.** | Keeps event emission in the service layer (closest to the recommended option c), reuses the existing visibility scope, and keeps the controller thin. |
| Generated columns vs. triggers for `search_vector_*`? | **Generated columns on `tasks` for title+description; listener-maintained `task_search_index` for completion notes.** | Generated columns are zero-code for static task fields; notes come from a related table so they need denormalized read model. |
| Synchronous or asynchronous index update? | **Asynchronous queued listener (`SearchIndexListener`).** | Stage completion must not block on index update; a few seconds of staleness is acceptable. |
| Recent activity deduplication at query or write time? | **Insert every activity row; deduplicate at query time with `DISTINCT ON (task_id)`.** | Preserves accurate history, avoids complex upsert logic, and the 90-day prune bounds table growth. |
| `TaskViewed` write deduplication window? | **Skip insert if the same user+task has a `TaskViewed` row within the last 5 minutes.** | Prevents flooding from repeated page refreshes while still recording revisits after a reasonable gap. |

---

## Technical Approach

Create a new `Search` module under `app/Modules/Search/` that owns two tenant tables (`task_search_index`, `user_recent_activity`), one enum, one read service, one controller, listeners, and one scheduled command. Full-text search is implemented with PostgreSQL `tsvector` generated columns on `tasks` and denormalized note vectors in `task_search_index`. ABAC/confidentiality filtering is delegated to `TaskVisibilityScope` exactly as FollowUp and Analytics do. Recent activity is populated by queued listeners consuming Task domain events. All search endpoints are read-only; no writes to Task/Tracking/IAM/Organization tables.

**Key decisions:**
- **Hybrid FTS index** — generated columns for static bilingual task text, event-driven denormalized table for stage completion notes.
- **Ranked cursor pagination** — order by combined `ts_rank` expression aliased as `combined_rank`, then `tasks.id`; Laravel cursor pagination encodes both values.
- **No result caching** — search results and recent activity must be real-time; only helper metadata (none in MVP) would be cached.
- **Queued listeners for index and activity writes** — decouples Search from Task transaction path, with idempotent upserts and 5-minute view deduplication.
- **No new capabilities** — visibility is governed by existing `task.view.*` grants via `TaskVisibilityScope`.

---

## Affected Modules / Files

### New Files (to create)

```
app/Modules/Search/
├── Enums/
│   └── SearchActivityType.php
├── Models/
│   ├── TaskSearchIndex.php
│   └── UserRecentActivity.php
├── Services/
│   ├── SearchService.php
│   ├── SearchIndexService.php
│   └── SearchActivityService.php
├── Controllers/
│   └── SearchController.php
├── Requests/
│   └── SearchTasksRequest.php
├── Resources/
│   ├── SearchTaskResource.php
│   └── RecentActivityResource.php
├── Listeners/
│   ├── UpdateSearchIndexOnStageAssignmentCompleted.php
│   ├── RecordActivityOnTaskViewed.php
│   ├── RecordActivityOnStageAssignmentCompleted.php
│   └── RecordActivityOnStageInstanceReturned.php
├── Exceptions/
│   ├── SearchQueryTooShortException.php
│   └── ExternalReferenceSearchNotAvailableException.php
└── Console/
    └── Commands/
        └── PruneRecentActivityCommand.php

app/Modules/Task/
├── Events/
│   └── TaskViewed.php
├── Services/
│   └── TaskService.php  (add findVisible)
└── Controllers/
    └── TaskController.php  (use findVisible)

database/migrations/tenant/
├── 2026_06_15_000001_add_search_vectors_to_tasks_table.php
├── 2026_06_15_000002_create_task_search_index_table.php
└── 2026_06_15_000003_create_user_recent_activity_table.php

routes/api/v1/search.php
tests/Feature/Modules/Search/
├── SearchTasksTest.php
└── RecentActivityTest.php
```

### Modified Files (to edit)

| File | Change |
|------|--------|
| `config/logging.php` | Add `search` daily log channel. |
| `routes/tenant.php` | `require __DIR__.'/api/v1/search.php';` |
| `routes/console.php` | Schedule `search:prune-recent-activity` daily. |
| `app/Modules/Task/Services/TaskService.php` | Add `findVisible(Task, User): Task` that applies `TaskVisibilityScope`, loads relations, emits `TaskViewed`. |
| `app/Modules/Task/Controllers/TaskController.php` | `show()` delegates to `taskService->findVisible()`; add `LIST` rate limit. |
| `openapi/openapi.json` | Regenerate after implementation. |

---

## Implementation Notes

### 1. Enum — `SearchActivityType`

**One-line summary:** Single int-backed enum for activity log rows.

**Key decisions:**
- Stored as TINYINT in `user_recent_activity.activity_type`.
- `CommentAdded` reserved for Spec 013; Search listener for it is not registered until comments exist.

**File:** `app/Modules/Search/Enums/SearchActivityType.php`

```php
<?php

namespace App\Modules\Search\Enums;

enum SearchActivityType: int
{
    case TaskViewed = 1;
    case StageCompleted = 2;
    case StageReturned = 3;
    case CommentAdded = 4;
}
```

**Test cases:**
1. `SearchActivityType::StageCompleted->value` → `2`
2. `SearchActivityType::tryFrom(4)` → `SearchActivityType::CommentAdded`

**Rules:** `coding-standards.md` — Enum Usage. No magic numbers in services.

---

### 2. Migrations

#### 2a. Add FTS generated columns to `tasks`

**One-line summary:** Add two `tsvector` generated columns and GIN indexes for fast title/description search.

**Key decisions:**
- Uses raw `ALTER TABLE` because generated `tsvector` columns are PostgreSQL-specific.
- `simple` config for Arabic (no stemming, handles prefixes/suffixes better); `english` config for English.
- GIN indexes are required for sub-50 ms full-text lookups.

**File:** `database/migrations/tenant/2026_06_15_000001_add_search_vectors_to_tasks_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks ADD COLUMN search_vector_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(title_ar,'') || ' ' || coalesce(description_ar,''))) STORED");
        DB::statement("ALTER TABLE tasks ADD COLUMN search_vector_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title_en,'') || ' ' || coalesce(description_en,''))) STORED");

        DB::statement('CREATE INDEX tasks_search_vector_ar_idx ON tasks USING GIN (search_vector_ar)');
        DB::statement('CREATE INDEX tasks_search_vector_en_idx ON tasks USING GIN (search_vector_en)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tasks_search_vector_ar_idx');
        DB::statement('DROP INDEX IF EXISTS tasks_search_vector_en_idx');
        Schema::table('tasks', function ($table) {
            $table->dropColumn(['search_vector_ar', 'search_vector_en']);
        });
    }
};
```

#### 2b. Create `task_search_index` table

**One-line summary:** Denormalized read model holding aggregated stage completion notes and their own FTS vectors.

**Key decisions:**
- One row per task; upserted by listener on `StageAssignmentCompleted`.
- Vectors are generated columns because they derive from the denormalized `notes_*` text.
- Uses `tsvector` columns with GIN indexes.

**File:** `database/migrations/tenant/2026_06_15_000002_create_task_search_index_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_search_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->text('notes_ar')->nullable();
            $table->text('notes_en')->nullable();
            $table->timestamps();
            $table->unique('task_id');
        });

        DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_notes_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(notes_ar,''))) STORED");
        DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_notes_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(notes_en,''))) STORED");

        DB::statement('CREATE INDEX task_search_index_vector_ar_idx ON task_search_index USING GIN (search_vector_notes_ar)');
        DB::statement('CREATE INDEX task_search_index_vector_en_idx ON task_search_index USING GIN (search_vector_notes_en)');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_search_index');
    }
};
```

#### 2c. Create `user_recent_activity` table

**One-line summary:** Append-only log of user-task interactions with a composite index for fast per-user recent lookup.

**Key decisions:**
- No `public_id` and no soft deletes — internal log only.
- Composite index on `(user_id, occurred_at DESC)`.
- No unique constraint on `(user_id, task_id)`; duplicates are collapsed at query time.

**File:** `database/migrations/tenant/2026_06_15_000003_create_user_recent_activity_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_recent_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedTinyInteger('activity_type');
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'occurred_at' => 'desc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recent_activity');
    }
};
```

**Rules:** `coding-standards.md` — Migrations. Additive only, proper indexes, no `tenant_id`.

---

### 3. Models

#### 3a. `TaskSearchIndex`

**File:** `app/Modules/Search/Models/TaskSearchIndex.php`

```php
<?php

namespace App\Modules\Search\Models;

use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'notes_ar', 'notes_en'])]
class TaskSearchIndex extends Model
{
    public const CREATED_AT = 'updated_at';

    public const UPDATED_AT = 'updated_at';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
```

#### 3b. `UserRecentActivity`

**File:** `app/Modules/Search/Models/UserRecentActivity.php`

```php
<?php

namespace App\Modules\Search\Models;

use App\Models\User;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'task_id', 'activity_type', 'occurred_at'])]
class UserRecentActivity extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'activity_type' => SearchActivityType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
```

**Rules:** `coding-standards.md` — Models. No `tenant_id`; use `casts()`; plain Eloquent for internal join tables.

---

### 4. Exceptions

**One-line summary:** Two domain exceptions extending `App\Exceptions\DomainException`.

**Files:**
- `app/Modules/Search/Exceptions/SearchQueryTooShortException.php` (422)
- `app/Modules/Search/Exceptions/ExternalReferenceSearchNotAvailableException.php` (422)

```php
<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class SearchQueryTooShortException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Search query must be at least 2 characters.');
    }
}
```

```php
<?php

namespace App\Modules\Search\Exceptions;

use App\Exceptions\DomainException;

class ExternalReferenceSearchNotAvailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct('External reference search is not yet available.');
    }
}
```

**Rules:** `coding-standards.md` — Error Handling. Registered automatically by existing `DomainException` handler in `bootstrap/app.php`.

---

### 5. Events — `TaskViewed`

**One-line summary:** New lightweight Task domain event emitted after a successful, visibility-authorized task show.

**Key decisions:**
- Lives in `app/Modules/Task/Events/` because it is a Task lifecycle event.
- Implements `ShouldDispatchAfterCommit`.
- Carries the visible `Task` and the viewing `User`.

**File:** `app/Modules/Task/Events/TaskViewed.php`

```php
<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TaskViewed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public User $user,
    ) {}
}
```

**Rules:** `coding-standards.md` — Domain Events must implement `ShouldDispatchAfterCommit`.

---

### 6. `TaskService::findVisible()` + `TaskController::show()` Update

**One-line summary:** Centralize task-show logic in the service, emit `TaskViewed` after visibility succeeds.

**File:** `app/Modules/Task/Services/TaskService.php`

```php
public function findVisible(Task $task, User $user): Task
{
    try {
        $visibleTask = $this->taskVisibilityScope->apply(
            Task::query()->where('id', $task->id),
            $user
        )->firstOrFail();

        $visibleTask->load([
            'priority', 'blueprint.category', 'initiator',
            'stageInstances.assignments.user',
            'stageInstances.subStageInstances',
        ]);

        event(new TaskViewed($visibleTask, $user));

        return $visibleTask;
    } catch (\Throwable $e) {
        Log::channel('task')->error('Failed to load visible task', [
            'tenant_slug' => tenant()?->slug ?? 'central',
            'action' => 'task.find_visible',
            'entity_type' => 'task',
            'entity_id' => $task->public_id,
            'performed_by' => $user->public_id,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

**File:** `app/Modules/Task/Controllers/TaskController.php`

```php
public function show(Request $request, Task $task): TaskDetailResource
{
    $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);
    $task = $this->taskService->findVisible($task, $request->user());

    return new TaskDetailResource($task);
}
```

**Rules:** `coding-standards.md` — Error Handling (try/catch + module channel), Events (`ShouldDispatchAfterCommit`), Rate Limiting (`LIST` for reads).

---

### 7. `SearchIndexService`

**One-line summary:** Idempotent upsert of aggregated completion notes into `task_search_index`.

**Key decisions:**
- Single write per call; no transaction required per spec.
- Aggregates all completed assignment notes for the task.
- Uses `updateOrCreate()` on `task_id`.
- try/catch + `Log::channel('search')`.

**File:** `app/Modules/Search/Services/SearchIndexService.php`

```php
<?php

namespace App\Modules\Search\Services;

use App\Modules\Search\Models\TaskSearchIndex;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageAssignment;
use Illuminate\Support\Facades\Log;

class SearchIndexService
{
    public function upsertForTask(Task $task): void
    {
        try {
            $notesAr = TaskStageAssignment::where('task_id', $task->id)
                ->where('is_completed', true)
                ->whereNotNull('completion_note')
                ->orderBy('completed_at')
                ->pluck('completion_note')
                ->implode("\n");

            TaskSearchIndex::updateOrCreate(
                ['task_id' => $task->id],
                ['notes_ar' => $notesAr ?: null, 'notes_en' => $notesAr ?: null]
            );
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to update search index', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'search.index_update',
                'entity_type' => 'task_search_index',
                'entity_id' => $task->public_id,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

**Test cases:**
1. After a stage assignment with note `"legal opinion"` is completed, `task_search_index.notes_ar` contains `"legal opinion"`.
2. Re-completing the same assignment with a new note updates the index idempotently (only one row per task).

**Rules:** `coding-standards.md` — Error Handling (try/catch + module channel); single write so no `DB::transaction()`.

---

### 8. `SearchActivityService`

**One-line summary:** Append recent-activity rows with 5-minute deduplication for views.

**Key decisions:**
- `recordView()` skips insert if a `TaskViewed` row exists within the last 5 minutes for this user+task.
- `recordStageCompleted()` and `recordStageReturned()` always insert.
- All methods are single writes, no transactions.

**File:** `app/Modules/Search/Services/SearchActivityService.php`

```php
<?php

namespace App\Modules\Search\Services;

use App\Models\User;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Models\UserRecentActivity;
use App\Modules\Task\Models\Task;
use Illuminate\Support\Facades\Log;

class SearchActivityService
{
    public function recordView(User $user, Task $task): void
    {
        $recent = UserRecentActivity::where('user_id', $user->id)
            ->where('task_id', $task->id)
            ->where('activity_type', SearchActivityType::TaskViewed)
            ->where('occurred_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recent) {
            return;
        }

        $this->insert($user, $task, SearchActivityType::TaskViewed);
    }

    public function recordStageCompleted(User $user, Task $task): void
    {
        $this->insert($user, $task, SearchActivityType::StageCompleted);
    }

    public function recordStageReturned(User $user, Task $task): void
    {
        $this->insert($user, $task, SearchActivityType::StageReturned);
    }

    private function insert(User $user, Task $task, SearchActivityType $type): void
    {
        try {
            UserRecentActivity::create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'activity_type' => $type,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to record recent activity', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'search.recent_activity',
                'entity_type' => 'user_recent_activity',
                'entity_id' => $task->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

**Rules:** `coding-standards.md` — Error Handling (try/catch + module channel); single writes so no transactions.

---

### 9. Listeners

**One-line summary:** Queued listeners for index updates and activity logging, all implementing `ShouldQueue`.

**Key decisions:**
- `$tries = 3`, `$backoff = [30, 60, 120]`.
- `UpdateSearchIndexOnStageAssignmentCompleted` calls `SearchIndexService::upsertForTask()`.
- Activity listeners call `SearchActivityService` with the acting user extracted from the event.
- `StageInstanceReturned` currently does not carry the returning user; add `public User $returnedByUser` to the event constructor (see note below).

**Files:**
- `app/Modules/Search/Listeners/UpdateSearchIndexOnStageAssignmentCompleted.php`
- `app/Modules/Search/Listeners/RecordActivityOnTaskViewed.php`
- `app/Modules/Search/Listeners/RecordActivityOnStageAssignmentCompleted.php`
- `app/Modules/Search/Listeners/RecordActivityOnStageInstanceReturned.php`

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchIndexService;
use App\Modules\Task\Events\StageAssignmentCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateSearchIndexOnStageAssignmentCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchIndexService $searchIndexService,
    ) {}

    public function handle(StageAssignmentCompleted $event): void
    {
        $this->searchIndexService->upsertForTask($event->assignment->task);
    }
}
```

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\TaskViewed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnTaskViewed implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(TaskViewed $event): void
    {
        $this->activityService->recordView($event->user, $event->task);
    }
}
```

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\StageAssignmentCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnStageAssignmentCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(StageAssignmentCompleted $event): void
    {
        $assignment = $event->assignment;
        $this->activityService->recordStageCompleted($assignment->user, $assignment->task);
    }
}
```

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\StageInstanceReturned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnStageInstanceReturned implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(StageInstanceReturned $event): void
    {
        $this->activityService->recordStageReturned(
            $event->returnedByUser,
            $event->returnedStageInstance->task
        );
    }
}
```

**Required event contract change:** `StageInstanceReturned` must carry the returning user. Update `app/Modules/Task/Events/StageInstanceReturned.php`:

```php
<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Task\Models\TaskStageInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class StageInstanceReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public TaskStageInstance $returnedStageInstance,
        public string $reason,
        public User $returnedByUser,
    ) {}
}
```

And update `StageLifecycleService::returnStage()` to pass `$user` when emitting the event:

```php
event(new StageInstanceReturned($stageInstance, $reason, $user));
```

**Test cases:**
1. Completing a stage with a note dispatches `StageAssignmentCompleted` → `UpdateSearchIndexOnStageAssignmentCompleted` updates `task_search_index`.
2. Viewing a task dispatches `TaskViewed` → `RecordActivityOnTaskViewed` creates a `UserRecentActivity` row (unless within 5-minute window).

**Rules:** `coding-standards.md` — Queues & Jobs (`ShouldQueue`, `$tries`, `$backoff`), Domain Events (`ShouldDispatchAfterCommit`).

---

### 10. `SearchService`

**One-line summary:** Builds the ABAC-filtered, full-text ranked task query and returns the recent-activity list.

**Key decisions:**
- Reuses `IntersectsTaskVisibility` concern from Analytics for base query (excludes drafts/archived/deleted, applies `TaskVisibilityScope`).
- Joins `task_search_index` with `LEFT JOIN` so tasks without notes still match on title/description.
- Query text is normalized to a `tsquery` with `&` between terms; terms under 2 chars are dropped; if no terms remain, throw `SearchQueryTooShortException`.
- Combines Arabic and English ranks with `GREATEST()` and aliases as `combined_rank` for cursor pagination.
- External reference filter uses `Schema::hasTable()` guard and `whereExists` to avoid coupling to a non-existent model.

**File:** `app/Modules/Search/Services/SearchService.php`

```php
<?php

namespace App\Modules\Search\Services;

use App\Models\User;
use App\Modules\Analytics\Services\Concerns\IntersectsTaskVisibility;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Organization\Models\Department;
use App\Modules\Search\Enums\SearchActivityType;
use App\Modules\Search\Exceptions\ExternalReferenceSearchNotAvailableException;
use App\Modules\Search\Exceptions\SearchQueryTooShortException;
use App\Modules\Search\Models\UserRecentActivity;
use App\Modules\Task\Enums\TaskStatus;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPriority;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SearchService
{
    use IntersectsTaskVisibility;

    public function searchTasks(User $user, array $filters): CursorPaginator
    {
        try {
            $tsqueryAr = $this->toTsquery($filters['q'], 'simple');
            $tsqueryEn = $this->toTsquery($filters['q'], 'english');

            $query = $this->baseTaskQuery($user)
                ->leftJoin('task_search_index', 'task_search_index.task_id', '=', 'tasks.id')
                ->selectRaw("tasks.*, GREATEST(
                    COALESCE(ts_rank(tasks.search_vector_ar, to_tsquery('simple', ?)), 0),
                    COALESCE(ts_rank(tasks.search_vector_en, to_tsquery('english', ?)), 0),
                    COALESCE(ts_rank(task_search_index.search_vector_notes_ar, to_tsquery('simple', ?)), 0),
                    COALESCE(ts_rank(task_search_index.search_vector_notes_en, to_tsquery('english', ?)), 0)
                ) as combined_rank", [$tsqueryAr, $tsqueryEn, $tsqueryAr, $tsqueryEn])
                ->where(function (Builder $q) use ($tsqueryAr, $tsqueryEn) {
                    $q->whereRaw('tasks.search_vector_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
                        ->orWhereRaw('tasks.search_vector_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn])
                        ->orWhereRaw('task_search_index.search_vector_notes_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
                        ->orWhereRaw('task_search_index.search_vector_notes_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn]);
                });

            $this->applyStructuredFilters($query, $filters);

            $query->orderByDesc('combined_rank')
                ->orderByDesc('tasks.id');

            $perPage = $filters['per_page'] ?? 15;

            return $query->cursorPaginate($perPage);
        } catch (SearchQueryTooShortException|ExternalReferenceSearchNotAvailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to search tasks', [
                'tenant_slug' => tenant()->slug,
                'action' => 'search.tasks',
                'entity_type' => 'task',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function recentActivity(User $user): Collection
    {
        try {
            $rows = DB::select(<<<'SQL'
                SELECT DISTINCT ON (ura.task_id)
                    ura.task_id,
                    ura.activity_type,
                    ura.occurred_at
                FROM user_recent_activity ura
                JOIN tasks ON tasks.id = ura.task_id
                WHERE ura.user_id = ?
                  AND tasks.deleted_at IS NULL
                ORDER BY ura.task_id, ura.occurred_at DESC
                LIMIT 20
            SQL, [$user->id]);

            if (empty($rows)) {
                return collect();
            }

            $taskIds = array_column($rows, 'task_id');
            $tasks = Task::whereIn('id', $taskIds)
                ->with(['priority'])
                ->get()
                ->keyBy('id');

            $activityByTask = collect($rows)->keyBy('task_id');

            return collect($taskIds)
                ->map(function ($taskId) use ($tasks, $activityByTask) {
                    $task = $tasks->get($taskId);
                    if (! $task) {
                        return null;
                    }

                    $task->_activity_type = SearchActivityType::from($activityByTask[$taskId]->activity_type);
                    $task->_occurred_at = $activityByTask[$taskId]->occurred_at;

                    return $task;
                })
                ->filter();
        } catch (\Throwable $e) {
            Log::channel('search')->error('Failed to fetch recent activity', [
                'tenant_slug' => tenant()->slug,
                'action' => 'search.recent',
                'entity_type' => 'user_recent_activity',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function toTsquery(string $q, string $config): string
    {
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q);
        $terms = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_filter($terms, fn ($t) => mb_strlen($t) >= 2);

        if (empty($terms)) {
            throw new SearchQueryTooShortException;
        }

        return implode(' & ', $terms);
    }

    private function applyStructuredFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $values = array_filter(array_map(
                fn ($s) => TaskStatus::tryFromName($s)?->value,
                $statuses
            ));
            if (! empty($values)) {
                $query->whereIn('tasks.status', $values);
            }
        }

        if (! empty($filters['priority_id'])) {
            $ids = (array) $filters['priority_id'];
            $query->whereHas('priority', fn ($q) => $q->whereIn('public_id', $ids));
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $field = $filters['date_field'] ?? 'created_at';
            $column = in_array($field, ['created_at', 'completed_at'], true) ? "tasks.{$field}" : 'tasks.created_at';
            if (! empty($filters['date_from'])) {
                $query->where($column, '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->where($column, '<=', $filters['date_to']);
            }
        }

        if (! empty($filters['department_id'])) {
            $departmentId = Department::where('public_id', $filters['department_id'])->value('id');
            if ($departmentId) {
                $query->whereHas('stageInstances', function ($sq) use ($departmentId) {
                    $sq->where('status', \App\Modules\Task\Enums\StageInstanceStatus::Active)
                        ->where('owning_department_id', $departmentId);
                });
            }
        }

        if (! empty($filters['blueprint_id'])) {
            $blueprintId = Blueprint::where('public_id', $filters['blueprint_id'])->value('id');
            if ($blueprintId) {
                $query->where('tasks.blueprint_id', $blueprintId);
            }
        }

        if (! empty($filters['blueprint_category_id'])) {
            $categoryId = BlueprintCategory::where('public_id', $filters['blueprint_category_id'])->value('id');
            if ($categoryId) {
                $query->whereHas('blueprint', fn ($q) => $q->where('blueprint_category_id', $categoryId));
            }
        }

        if (! empty($filters['external_reference'])) {
            if (! Schema::hasTable('task_external_references')) {
                throw new ExternalReferenceSearchNotAvailableException;
            }

            $referenceNumber = $filters['external_reference'];
            $query->whereExists(function ($sub) use ($referenceNumber) {
                $sub->selectRaw('1')
                    ->from('task_external_references')
                    ->whereColumn('task_external_references.task_id', 'tasks.id')
                    ->where('task_external_references.reference_number', $referenceNumber);
            });
        }
    }
}
```

**Notes / TODOs:**
- `TaskStatus::tryFromName()` is assumed to exist; if not, map status strings to enum values inline or add the helper to `TaskStatus`. <!-- TODO: verify -->
- Snippet generation (`snippet_ar`, `snippet_en`) can be added in a follow-up optimization using `ts_headline()`; include a basic version in the first implementation if time permits. <!-- TODO: verify -->

**Test cases:**
1. Search query `"budget ceiling"` returns tasks whose title/description or notes contain those words, ranked higher for title matches.
2. User with no `task.view.*` capability receives an empty cursor page.

**Rules:** `coding-standards.md` — Cursor pagination on large tables; no caching of search results; try/catch + module channel; enums instead of magic numbers.

---

### 11. `SearchController`

**One-line summary:** Thin controller validating requests, checking rate limits, and returning API Resources.

**Key decisions:**
- `tasks()` and `recent()` both use `RateLimits::LIST`.
- `GET /api/v1/search` is an alias to `tasks()`.
- Search result collection uses `SearchTaskResource` and manual cursor pagination envelope.

**File:** `app/Modules/Search/Controllers/SearchController.php`

```php
<?php

namespace App\Modules\Search\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Search\Requests\SearchTasksRequest;
use App\Modules\Search\Resources\RecentActivityResource;
use App\Modules\Search\Resources\SearchTaskResource;
use App\Modules\Search\Services\SearchService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private SearchService $searchService,
    ) {}

    public function tasks(SearchTasksRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->searchService->searchTasks($request->user(), $request->validated());
        $paginator->through(fn ($task) => new SearchTaskResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function search(SearchTasksRequest $request)
    {
        return $this->tasks($request);
    }

    public function recent(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $tasks = $this->searchService->recentActivity($request->user());

        return RecentActivityResource::collection($tasks);
    }
}
```

**Rules:** `coding-standards.md` — Controllers are thin; rate limiting via `HasRateLimiting` trait; API Resources required.

---

### 12. Form Request — `SearchTasksRequest`

**One-line summary:** Validates full-text query and all structured filters.

**Key decisions:**
- `q` required, string, min 2, max 200.
- `status` accepts array of `active|suspended|completed|cancelled`.
- `priority_id` accepts array of UUID strings.
- `date_field` only `created_at` or `completed_at`.

**File:** `app/Modules/Search/Requests/SearchTasksRequest.php`

```php
<?php

namespace App\Modules\Search\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:active,suspended,completed,cancelled'],
            'priority_id' => ['nullable', 'array'],
            'priority_id.*' => ['string', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'date_field' => ['nullable', 'string', 'in:created_at,completed_at'],
            'department_id' => ['nullable', 'string', 'uuid'],
            'blueprint_id' => ['nullable', 'string', 'uuid'],
            'blueprint_category_id' => ['nullable', 'string', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation in Form Request classes.

---

### 13. API Resources

#### 13a. `SearchTaskResource`

**File:** `app/Modules/Search/Resources/SearchTaskResource.php`

```php
<?php

namespace App\Modules\Search\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;
        $activeStage = $task->stageInstances
            ->first(fn ($s) => $s->status === \App\Modules\Task\Enums\StageInstanceStatus::Active);

        return [
            'public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en ?? $task->title_ar,
            'status' => $task->status,
            'priority' => $task->priority ? [
                'public_id' => $task->priority->public_id,
                'name_ar' => $task->priority->name_ar,
                'name_en' => $task->priority->name_en,
            ] : null,
            'classification_level' => $task->classification_level,
            'current_stage' => $activeStage ? [
                'public_id' => $activeStage->blueprintStage?->public_id,
                'name_ar' => $activeStage->blueprintStage?->name_ar,
                'name_en' => $activeStage->blueprintStage?->name_en,
                'stage_type' => $activeStage->blueprintStage?->stageType ? [
                    'public_id' => $activeStage->blueprintStage->stageType->public_id,
                    'name_ar' => $activeStage->blueprintStage->stageType->name_ar,
                    'name_en' => $activeStage->blueprintStage->stageType->name_en,
                ] : null,
            ] : null,
            'department' => $activeStage?->owningDepartment ? [
                'public_id' => $activeStage->owningDepartment->public_id,
                'name_ar' => $activeStage->owningDepartment->name_ar,
                'name_en' => $activeStage->owningDepartment->name_en,
            ] : null,
            'blueprint_category' => $task->blueprint?->category ? [
                'public_id' => $task->blueprint->category->public_id,
                'name_ar' => $task->blueprint->category->name_ar,
                'name_en' => $task->blueprint->category->name_en,
            ] : null,
            'due_date' => $task->due_date?->toDateString(),
            'created_at' => $task->created_at?->toIso8601String(),
            'snippet_ar' => null, // populated by SearchService if ts_headline implemented
            'snippet_en' => null, // populated by SearchService if ts_headline implemented
        ];
    }
}
```

#### 13b. `RecentActivityResource`

**File:** `app/Modules/Search/Resources/RecentActivityResource.php`

```php
<?php

namespace App\Modules\Search\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecentActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        return [
            'public_id' => $task->public_id,
            'title_ar' => $task->title_ar,
            'title_en' => $task->title_en ?? $task->title_ar,
            'status' => $task->status,
            'activity_type' => $task->_activity_type->name,
            'occurred_at' => $task->_occurred_at,
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources required; expose only `public_id`; eager-load relationships to avoid N+1.

---

### 14. Prune Command + Scheduler

**One-line summary:** Daily command removes `user_recent_activity` rows older than 90 days.

**File:** `app/Modules/Search/Console/Commands/PruneRecentActivityCommand.php`

```php
<?php

namespace App\Modules\Search\Console\Commands;

use App\Modules\Search\Models\UserRecentActivity;
use Illuminate\Console\Command;

class PruneRecentActivityCommand extends Command
{
    protected $signature = 'search:prune-recent-activity {--days=90}';

    protected $description = 'Prune user recent activity older than the given number of days.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $count = UserRecentActivity::where('occurred_at', '<', $cutoff)->delete();
        $this->info("Pruned {$count} recent activity rows older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
```

**File:** `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('search:prune-recent-activity')->daily();
```

**Rules:** `coding-standards.md` — Queues & Jobs (scheduler for recurring cleanup).

---

### 15. Routes

**File:** `routes/api/v1/search.php`

```php
<?php

use App\Modules\Search\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('search')->group(function () {
    Route::get('/', [SearchController::class, 'search']);
    Route::get('tasks', [SearchController::class, 'tasks']);
    Route::get('recent', [SearchController::class, 'recent']);
});
```

**File:** `routes/tenant.php`

Add inside the tenant route group:

```php
require __DIR__.'/api/v1/search.php';
```

**Rules:** `coding-standards.md` — Versioned, kebab-case routes.

---

### 16. Logging Channel

**File:** `config/logging.php`

Add to `channels` array:

```php
'search' => [
    'driver' => 'daily',
    'path' => storage_path('logs/search/search.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
    'replace_placeholders' => true,
],
```

**Rules:** `coding-standards.md` — Per-module logging channel.

---

### 17. Feature Tests

**Files:**
- `tests/Feature/Modules/Search/SearchTasksTest.php`
- `tests/Feature/Modules/Search/RecentActivityTest.php`

**Key test cases (search):**
- Full-text search in Arabic matches morphological variants handled by `simple` config.
- Full-text search in English matches stemmed words handled by `english` config.
- Combined Arabic+English match across title/description.
- `q` too short or empty → 422.
- Status filter returns only selected statuses.
- Priority filter with multiple UUIDs.
- Date range filter on `created_at` and `completed_at`.
- Department filter scopes to active stage owning department.
- Blueprint / blueprint category filters.
- External reference exact match when table exists; 422 when it does not.
- ABAC filtering: org-wide vs department-touched vs no grants → empty.
- Confidential task exclusion for non-participants.
- Draft tasks excluded.
- Cursor pagination contract (`data`, `next_cursor`, `has_more`) and stable ordering.

**Key test cases (recent activity):**
- Last 20 distinct tasks for authenticated user.
- Deduplication: same task appears once with the most recent activity type.
- Deleted (soft-deleted) tasks excluded; cancelled tasks remain.
- User A cannot see user B activity.
- `TaskViewed` 5-minute deduplication at write time.
- `PruneRecentActivityCommand` removes rows older than 90 days.

**Rules:** `testing-policy.md` — Feature tests mandatory; use factories; `RefreshDatabase`; assert cursor pagination shape.

---

## Execution Order

| Step | What | Depends On |
|------|------|------------|
| 1 | Add `search` channel to `config/logging.php`. | — |
| 2 | Create `SearchActivityType` enum. | — |
| 3 | Add FTS generated columns + GIN indexes to `tasks` (migration 1). | — |
| 4 | Create `task_search_index` table (migration 2). | — |
| 5 | Create `user_recent_activity` table (migration 3). | — |
| 6 | Create `TaskSearchIndex` and `UserRecentActivity` models. | Steps 4–5 |
| 7 | Create Search exceptions. | — |
| 8 | Create `TaskViewed` event in Task module. | — |
| 9 | Add `TaskService::findVisible()` and update `TaskController::show()`. | Step 8 |
| 10 | Create `SearchIndexService`. | Step 6 |
| 11 | Create `SearchActivityService`. | Step 6 |
| 12 | Create queued Search listeners. | Steps 10–11 |
| 13 | Add returning user to `StageInstanceReturned` event + update `StageLifecycleService`. | Step 12 |
| 14 | Create `SearchService`. | Steps 3–6 |
| 15 | Create `SearchTasksRequest`, `SearchTaskResource`, `RecentActivityResource`. | Steps 2, 6 |
| 16 | Create `SearchController`. | Steps 14–15 |
| 17 | Create `routes/api/v1/search.php` and require it in `routes/tenant.php`. | Step 16 |
| 18 | Create `PruneRecentActivityCommand` and schedule it. | Step 5 |
| 19 | Run migrations on template DB and write feature tests. | Steps 1–18 |
| 20 | Run `php artisan test --compact` and fix failures. | Step 19 |
| 21 | Run `vendor/bin/pint --dirty --format agent`. | Step 20 |
| 22 | Regenerate `openapi/openapi.json`. | Step 17 |

---

## API Contract Summary

| Method | Endpoint | Auth | Rate Limit | Description |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/search` | Sanctum | `LIST` | Alias for `/api/v1/search/tasks`. |
| GET | `/api/v1/search/tasks` | Sanctum | `LIST` | Cursor-paginated full-text task search with structured filters. |
| GET | `/api/v1/search/recent` | Sanctum | `LIST` | Last 20 distinct tasks the caller interacted with (non-paginated). |

### Search endpoint request parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `q` | string | yes | Search query, 2–200 chars. |
| `status` | string[] | no | One or more: `active`, `suspended`, `completed`, `cancelled`. |
| `priority_id` | string[] | no | Priority `public_id` values. |
| `date_from` | date | no | Inclusive start date. |
| `date_to` | date | no | Inclusive end date. |
| `date_field` | string | no | `created_at` (default) or `completed_at`. |
| `department_id` | string (uuid) | no | Active stage owning department `public_id`. |
| `blueprint_id` | string (uuid) | no | Blueprint `public_id`. |
| `blueprint_category_id` | string (uuid) | no | Blueprint category `public_id`. |
| `external_reference` | string | no | Exact reference number match (422 if Spec 014 not present). |
| `per_page` | integer | no | Cursor page size, 1–100, default 15. |

### Response shapes

**Search (`/search/tasks`):**
```json
{
  "data": [
    {
      "public_id": "...",
      "title_ar": "...",
      "title_en": "...",
      "status": "active",
      "priority": { "public_id": "...", "name_ar": "...", "name_en": "..." },
      "classification_level": "internal",
      "current_stage": { "public_id": "...", "name_ar": "...", "stage_type": { "public_id": "...", "name_ar": "..." } },
      "department": { "public_id": "...", "name_ar": "..." },
      "blueprint_category": { "public_id": "...", "name_ar": "..." },
      "due_date": "2026-06-20",
      "created_at": "...",
      "snippet_ar": null,
      "snippet_en": null
    }
  ],
  "next_cursor": "...",
  "has_more": true
}
```

**Recent activity (`/search/recent`):**
```json
{
  "data": [
    {
      "public_id": "...",
      "title_ar": "...",
      "title_en": "...",
      "status": "active",
      "activity_type": "StageCompleted",
      "occurred_at": "..."
    }
  ]
}
```

---

## What to Test Manually

1. **Happy path search** — Search for a word present only in `description_ar` and verify the task is returned.
2. **Arabic variant search** — Search for `تواصل` and verify a task containing `التواصل` in title/description is found (simple config).
3. **English stemming** — Search for `budgets` and verify a task with `budget` in description is found (english config).
4. **Note search** — Complete a stage with note `"procurement clause objection"`, then search `"procurement"` and verify the task appears.
5. **Filter composition** — Combine `q`, `status=active`, `priority_id`, and `department_id`; verify all filters apply with AND logic.
6. **External reference 014 guard** — With no `task_external_references` table, pass `external_reference` and confirm 422.
7. **ABAC visibility** — Log in as user with only own-participation visibility; search and confirm only own/initiated/assigned tasks appear.
8. **Confidential exclusion** — Non-participant user cannot find a confidential task via search even with org-wide capability (unless `task.confidential.view_metadata`).
9. **Draft exclusion** — Create a draft task with a unique word; verify it never appears in search results.
10. **Cursor pagination** — Search a common term, paginate through results, confirm `has_more` and stable ordering.
11. **Recent activity** — View a task, complete a stage, return a stage; confirm `/search/recent` shows last 20 distinct tasks with most recent activity type.
12. **Recent activity isolation** — User A's recent activity feed does not contain User B's tasks.
13. **Recent activity deleted-task exclusion** — Soft-delete a task; verify it disappears from recent activity (cancelled tasks remain).
14. **View deduplication** — View the same task twice within 5 minutes; verify only one `TaskViewed` row is written.
15. **Prune command** — Insert a row with `occurred_at` 91 days ago, run `search:prune-recent-activity`, verify it is deleted.
16. **Rate limiting** — Exceed 60 requests/minute to `/search/tasks` and confirm 429 with `Retry-After`.
17. **Listener failure** — Force a queue worker error in `UpdateSearchIndexOnStageAssignmentCompleted`; verify the stage completion HTTP request still succeeds and the job retries.
18. **Tenant isolation** — Search in tenant A; verify no tenant B tasks leak.
