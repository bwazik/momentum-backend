# Plan: Comments & Collaboration

> **Spec:** 013-comments-collaboration
> **Date:** 2026-07-01
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| **Bilingual comment body** | **Single `body` column.** | The UI accepts text in whichever language the user types. No `body_ar` / `body_en` split in MVP. |
| **Comment list ordering** | **Oldest-first with cursor pagination.** | Top-level comments use `orderBy('id')` ascending with `cursorPaginate()`. Nested replies return as a full list (< 50 expected per top-level comment), also oldest-first. |
| **Inline attachments vs. separate endpoints** | **Separate endpoints.** | Create the comment first, then upload files to `POST /api/v1/comments/{comment}/documents`. Reuses the Document module with no new upload logic in Task. |
| **Comments in task timeline** | **Keep separate.** | Comments have their own panel; they are not merged into the Spec 006 timeline response in MVP. |
| **Soft-deleted comments in search** | **Deferred to V2.** | A future `CommentDeleted` event will trigger search index cleanup. Current indexer already filters `whereNull('deleted_at')`. |

---

## Technical Approach

Add a `comments` table and a small Task-module subsystem (model, service, controller, request, resource, event) for single-level threaded comments. Reuse the existing Document module for comment attachments by extending `DocumentService` and `DocumentAttachmentController` with comment-aware methods. Emit a `CommentCreated` domain event that Audit records automatically and that Search consumes to update `task_search_index` and `user_recent_activity`. All authorization flows through the existing `TaskVisibilityScope` — no new capabilities are required.

**Key decisions:**
- **No new module** — comments live inside the Task module because they are task-scoped and use task visibility rules.
- **Separate upload endpoint** — avoids duplicating file-handling logic and respects the Document module's existing validation, storage, and audit events.
- **Event-driven Search integration** — comment text is aggregated into `task_search_index` asynchronously so comment creation stays fast.
- **Existing `DomainException` rendering** — new comment exceptions extend `DomainException`; no extra bootstrap registration needed.

---

## Affected Modules / Files

### New Files

| File | Purpose |
|------|---------|
| `database/migrations/tenant/2026_07_01_000001_create_comments_table.php` | `comments` table with self-referential reply support |
| `database/migrations/tenant/2026_07_01_000002_add_comment_content_to_task_search_index.php` | Additive FTS columns for comment content |
| `database/factories/CommentFactory.php` | Factory for tests |
| `app/Modules/Task/Models/Comment.php` | Comment model + relationships |
| `app/Modules/Task/Requests/StoreCommentRequest.php` | Validation rules for create/reply |
| `app/Modules/Task/Exceptions/InvalidCommentParentException.php` | 422 when parent is invalid |
| `app/Modules/Task/Services/CommentService.php` | Create and list comments |
| `app/Modules/Task/Controllers/CommentController.php` | Comment HTTP API |
| `app/Modules/Task/Resources/CommentResource.php` | JSON shape for comments |
| `app/Modules/Task/Events/CommentCreated.php` | Domain event + audit data |
| `app/Modules/Search/Listeners/UpdateSearchIndexOnCommentCreated.php` | Queued search-index update |
| `app/Modules/Search/Listeners/RecordActivityOnCommentCreated.php` | Queued recent-activity write |
| `tests/Feature/Modules/Task/CommentTest.php` | Feature tests |

### Modified Files

| File | Change |
|------|--------|
| `app/Modules/Task/Models/Task.php` | Add `comments()` HasMany relationship |
| `routes/api/v1/tasks.php` | Register `GET/POST {task}/comments` |
| `app/Modules/Document/Services/DocumentService.php` | Add `uploadForComment()` and `resolveTask()` case for `Comment` |
| `app/Modules/Document/Controllers/DocumentAttachmentController.php` | Add `listForComment()` and `uploadForComment()` |
| `routes/api/v1/documents.php` | Uncomment comment attachment routes |
| `app/Modules/Search/Models/TaskSearchIndex.php` | Add `comment_content_ar` / `comment_content_en` to fillable |
| `app/Modules/Search/Services/SearchIndexService.php` | Aggregate comment bodies into search index |
| `app/Modules/Search/Services/SearchService.php` | Include comment vectors in FTS rank and snippets |
| `app/Modules/Search/Services/SearchActivityService.php` | Add `recordCommentAdded()` |
| `openapi/openapi.json` | Regenerate after routes are stable |

---

## Implementation Notes

### 1. Migrations

**One-line summary:** Create the `comments` table and extend `task_search_index` with comment FTS columns.

**Key decisions:**
- Single-level threading via `parent_comment_id` self-FK; no depth enforcement in DB — enforced in service.
- `task_search_index` gets `comment_content_ar` and `comment_content_en` plus generated `tsvector` columns on PostgreSQL only.

**Files:**
- `database/migrations/tenant/2026_07_01_000001_create_comments_table.php`
- `database/migrations/tenant/2026_07_01_000002_add_comment_content_to_task_search_index.php`

**Code snippet — create comments table:**

```php
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->uuid('public_id')->unique();
    $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');
    $table->foreignId('parent_comment_id')->nullable()->constrained('comments');
    $table->text('body');
    $table->timestamps();
    $table->softDeletes();

    $table->index('task_id');
    $table->index(['task_id', 'parent_comment_id']);
});
```

**Code snippet — extend search index:**

```php
Schema::table('task_search_index', function (Blueprint $table) {
    $table->text('comment_content_ar')->nullable()->after('notes_en');
    $table->text('comment_content_en')->nullable()->after('comment_content_ar');
});

if (DB::connection()->getDriverName() === 'pgsql') {
    DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_comments_ar tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(comment_content_ar,''))) STORED");
    DB::statement("ALTER TABLE task_search_index ADD COLUMN search_vector_comments_en tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(comment_content_en,''))) STORED");
    DB::statement('CREATE INDEX task_search_index_comments_vector_ar_idx ON task_search_index USING GIN (search_vector_comments_ar)');
    DB::statement('CREATE INDEX task_search_index_comments_vector_en_idx ON task_search_index USING GIN (search_vector_comments_en)');
}
```

**Rules:** `coding-standards.md` § Migrations — tenant migrations, no `tenant_id`, additive changes only.

---

### 2. Comment Model

**One-line summary:** Task-module model extending `TenantModel` with soft deletes and document relationship.

**Key decisions:**
- Reuse `HasPublicId` for UUID v7 `public_id`.
- `documents()` relation filters `Document` by `entity_type = Comment`.

**Files:**
- `app/Modules/Task/Models/Comment.php`
- `app/Modules/Task/Models/Task.php` (add `comments()` relation)

**Code snippet — Comment model:**

```php
<?php

namespace App\Modules\Task\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Models\Document;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['task_id', 'user_id', 'parent_comment_id', 'body'])]
class Comment extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'entity_id')
            ->where('entity_type', DocumentEntityType::Comment);
    }
}
```

**Code snippet — Task relation:**

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class)->orderBy('id');
}
```

**Test cases:**
1. `Comment::factory()->create()` → row exists with UUID `public_id`.
2. `$comment->replies()->create([...])` → reply has `parent_comment_id` set.

**Rules:** `coding-standards.md` § Models — no `tenant_id`, `public_id` route binding via `TenantModel`.

---

### 3. Form Request & Exception

**One-line summary:** Validate comment body and optional parent; enforce single-level threading in the service.

**Files:**
- `app/Modules/Task/Requests/StoreCommentRequest.php`
- `app/Modules/Task/Exceptions/InvalidCommentParentException.php`

**Code snippet — request:**

```php
<?php

namespace App\Modules\Task\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_comment_id' => ['nullable', 'string', 'exists:comments,public_id'],
        ];
    }
}
```

**Code snippet — exception:**

```php
<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidCommentParentException extends DomainException
{
    public function __construct(string $message = 'Invalid comment parent.')
    {
        parent::__construct($message);
    }
}
```

**Rules:** `coding-standards.md` § Error Handling — extend `DomainException`; bootstrap already renders it.

---

### 4. Comment Service

**One-line summary:** Create top-level comments and single-level replies; list top-level comments with nested replies.

**Key decisions:**
- Parent validation happens here because it needs the task context.
- `create()` emits `CommentCreated($comment->load('task'), $user)` after the insert.
- List query eager-loads `task`, `user`, `replies.user`, and document count to avoid N+1.

**Files:**
- `app/Modules/Task/Services/CommentService.php`

**Code snippet:**

```php
<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Events\CommentCreated;
use App\Modules\Task\Exceptions\InvalidCommentParentException;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Traits\AuthenticatedUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;

class CommentService
{
    use AuthenticatedUser;

    public function create(Task $task, array $data, User $user): Comment
    {
        try {
            $parentId = null;

            if (! empty($data['parent_comment_id'])) {
                $parent = Comment::where('public_id', $data['parent_comment_id'])->first();

                if (! $parent || $parent->task_id !== $task->id || $parent->parent_comment_id !== null) {
                    throw new InvalidCommentParentException('Parent must be a top-level comment on the same task.');
                }

                $parentId = $parent->id;
            }

            $comment = Comment::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'parent_comment_id' => $parentId,
                'body' => $data['body'],
            ]);

            event(new CommentCreated($comment->load('task'), $user));

            return $comment->fresh(['user']);
        } catch (InvalidCommentParentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('task')->error('Failed to create comment', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'comment.create',
                'entity_type' => 'comment',
                'entity_id' => null,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listForTask(Task $task, int $perPage = 15): CursorPaginator
    {
        return Comment::where('task_id', $task->id)
            ->whereNull('parent_comment_id')
            ->with(['task', 'user', 'replies.user'])
            ->withCount('documents')
            ->orderBy('id')
            ->cursorPaginate($perPage);
    }
}
```

**Test cases:**
1. Create reply to top-level comment on task A → success, `parent_comment_id` set.
2. Create reply to a reply on task A → throws `InvalidCommentParentException`.

**Rules:** `coding-standards.md` § Database Transactions (single write — no transaction), § Error Handling (try/catch + `task` channel), § Events (`ShouldDispatchAfterCommit` on event).

---

### 5. Comment Controller

**One-line summary:** Thin controller that checks task visibility, rate limits, and delegates to `CommentService`.

**Key decisions:**
- Use `TaskVisibilityScope` directly so that listing/creating comments does not emit `TaskViewed`.
- Cursor-paginated response returns `{data, next_cursor, has_more}`.

**Files:**
- `app/Modules/Task/Controllers/CommentController.php`

**Code snippet:**

```php
<?php

namespace App\Modules\Task\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Requests\StoreCommentRequest;
use App\Modules\Task\Resources\CommentResource;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Modules\Task\Services\CommentService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private CommentService $commentService,
        private TaskVisibilityScope $taskVisibilityScope,
    ) {}

    public function index(Request $request, Task $task)
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->commentService->listForTask(
            $task,
            $request->integer('per_page', 15)
        )->through(fn (Comment $comment) => new CommentResource($comment));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function store(StoreCommentRequest $request, Task $task): CommentResource
    {
        $this->guardVisible($task, $request->user());
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $comment = $this->commentService->create($task, $request->validated(), $request->user());

        return new CommentResource($comment->load('user'));
    }

    private function guardVisible(Task $task, User $user): void
    {
        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            abort(403, 'You do not have access to this task.');
        }
    }
}
```

**Rules:** `coding-standards.md` § Controllers (thin), § Rate Limiting (`RateLimits::LIST` / `RateLimits::MUTATE`), § Pagination (cursor pagination with `orderBy('id')`).

---

### 6. Comment Resource

**One-line summary:** JSON shape with author, body, optional parent, nested replies, and attachment count.

**Files:**
- `app/Modules/Task/Resources/CommentResource.php`

**Code snippet:**

```php
<?php

namespace App\Modules\Task\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'task_id' => $this->task?->public_id,
            'author' => [
                'public_id' => $this->user?->public_id,
                'name_ar' => $this->user?->name_ar,
                'name_en' => $this->user?->name_en,
            ],
            'body' => $this->body,
            'parent_comment_id' => $this->parent?->public_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachment_count' => $this->whenCounted('documents'),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
        ];
    }
}
```

**Rules:** `coding-standards.md` § API Resources — `public_id` only, no internal `id`.

---

### 7. Domain Event & Audit

**One-line summary:** `CommentCreated` implements `ShouldDispatchAfterCommit` and `ProvidesAuditData`.

**Key decisions:**
- Carries both the comment and the acting user.
- Audit payload stores a truncated body snapshot.

**Files:**
- `app/Modules/Task/Events/CommentCreated.php`

**Code snippet:**

```php
<?php

namespace App\Modules\Task\Events;

use App\Models\User;
use App\Modules\Audit\Contracts\ProvidesAuditData;
use App\Modules\Audit\Data\AuditEventData;
use App\Modules\Audit\Enums\AuditEntityType;
use App\Modules\Task\Models\Comment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CommentCreated implements ProvidesAuditData, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Comment $comment,
        public User $user,
    ) {}

    public function auditData(): AuditEventData
    {
        return new AuditEventData(
            eventType: 'comment.created',
            entityType: AuditEntityType::Comment,
            entityId: $this->comment->id,
            entityPublicId: $this->comment->public_id,
            rootEntityType: AuditEntityType::Task,
            rootEntityId: $this->comment->task_id,
            rootEntityPublicId: $this->comment->task?->public_id,
            user: $this->user,
            payload: [
                'body' => mb_strimwidth($this->comment->body, 0, 1000, '...'),
            ],
        );
    }
}
```

**Rules:** `coding-standards.md` § Queues & Jobs — domain events implement `ShouldDispatchAfterCommit`; Audit receives events, never queried back.

---

### 8. Document Module Comment Attachments

**One-line summary:** Activate the deferred comment endpoints by extending the Document service and controller.

**Key decisions:**
- `DocumentEntityType::Comment` already exists; no new enum needed.
- `resolveTask()` for comments loads `Comment::find($id)?->task` so visibility checks reuse `TaskVisibilityScope`.

**Files:**
- `app/Modules/Document/Services/DocumentService.php`
- `app/Modules/Document/Controllers/DocumentAttachmentController.php`
- `routes/api/v1/documents.php`

**Code snippet — DocumentService additions:**

```php
use App\Modules\Task\Models\Comment;

public function uploadForComment(Comment $comment, array $data, User $uploader): Document
{
    $this->guardTaskVisibility(DocumentEntityType::Comment, $comment->id, $uploader);

    return $this->upload(DocumentEntityType::Comment, $comment->id, $data, $uploader);
}

private function resolveTask(DocumentEntityType $entityType, int $entityId): ?Task
{
    return match ($entityType) {
        DocumentEntityType::Task => Task::find($entityId),
        DocumentEntityType::StageOutput => TaskStageInstance::find($entityId)?->task,
        DocumentEntityType::Comment => Comment::find($entityId)?->task,
        DocumentEntityType::HelpArticle => null,
    };
}
```

**Code snippet — DocumentAttachmentController additions:**

```php
use App\Modules\Task\Models\Comment;

public function listForComment(Request $request, Comment $comment)
{
    $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

    $perPage = min(100, max(1, $request->integer('per_page', 15)));
    $paginator = $this->documentService
        ->listForEntity(DocumentEntityType::Comment, $comment->id, $request->user(), $perPage)
        ->through(fn ($doc) => new DocumentResource($doc));

    return response()->json([
        'data' => $paginator->items(),
        'next_cursor' => $paginator->nextCursor()?->encode(),
        'has_more' => $paginator->hasMorePages(),
    ]);
}

public function uploadForComment(UploadDocumentRequest $request, Comment $comment): DocumentResource
{
    $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

    $document = $this->documentService->uploadForComment($comment, $request->validated(), $request->user());

    return new DocumentResource($document);
}
```

**Code snippet — route changes:**

```php
// Comment attachments
Route::get('comments/{comment}/documents', [DocumentAttachmentController::class, 'listForComment']);
Route::post('comments/{comment}/documents', [DocumentAttachmentController::class, 'uploadForComment']);
```

**Test cases:**
1. Upload document to comment on visible task → success, `entity_type = Comment`.
2. List documents on comment user cannot view → 403.

**Rules:** `coding-standards.md` § Rate Limiting, § Module Boundaries (Document resolves task via service method, no cross-module joins).

---

### 9. Search Index Integration

**One-line summary:** Aggregate comment bodies into `task_search_index` and include them in FTS rank/snippets.

**Key decisions:**
- Copy the same comment text into both `comment_content_ar` and `comment_content_en` so it is searchable with both `simple` and `english` configurations.
- Add a queued listener for `CommentCreated`.

**Files:**
- `app/Modules/Search/Models/TaskSearchIndex.php`
- `app/Modules/Search/Services/SearchIndexService.php`
- `app/Modules/Search/Services/SearchService.php`
- `app/Modules/Search/Listeners/UpdateSearchIndexOnCommentCreated.php`

**Code snippet — TaskSearchIndex fillable update:**

```php
#[Fillable(['task_id', 'notes_ar', 'notes_en', 'comment_content_ar', 'comment_content_en'])]
```

**Code snippet — SearchIndexService update:**

```php
use App\Modules\Task\Models\Comment;

public function upsertForTask(Task $task): void
{
    try {
        $notesAr = TaskStageAssignment::where('task_id', $task->id)
            ->where('is_completed', true)
            ->whereNotNull('completion_note_ar')
            ->orderBy('completed_at')
            ->pluck('completion_note_ar')
            ->implode("\n");

        $notesEn = TaskStageAssignment::where('task_id', $task->id)
            ->where('is_completed', true)
            ->whereNotNull('completion_note_en')
            ->orderBy('completed_at')
            ->pluck('completion_note_en')
            ->implode("\n");

        $commentContent = Comment::where('task_id', $task->id)
            ->whereNull('deleted_at')
            ->pluck('body')
            ->implode("\n");

        TaskSearchIndex::updateOrCreate(
            ['task_id' => $task->id],
            [
                'notes_ar' => $notesAr ?: null,
                'notes_en' => $notesEn ?: null,
                'comment_content_ar' => $commentContent ?: null,
                'comment_content_en' => $commentContent ?: null,
            ]
        );
    } catch (\Throwable $e) {
        // existing log block
    }
}
```

**Code snippet — SearchService FTS additions:**

```php
$query->selectRaw("tasks.*, GREATEST(
    COALESCE(ts_rank(tasks.search_vector_ar, to_tsquery('simple', ?)), 0),
    COALESCE(ts_rank(tasks.search_vector_en, to_tsquery('english', ?)), 0),
    COALESCE(ts_rank(task_search_index.search_vector_notes_ar, to_tsquery('simple', ?)), 0),
    COALESCE(ts_rank(task_search_index.search_vector_notes_en, to_tsquery('english', ?)), 0),
    COALESCE(ts_rank(task_search_index.search_vector_comments_ar, to_tsquery('simple', ?)), 0),
    COALESCE(ts_rank(task_search_index.search_vector_comments_en, to_tsquery('english', ?)), 0)
) as combined_rank", [$tsqueryAr, $tsqueryEn, $tsqueryAr, $tsqueryEn, $tsqueryAr, $tsqueryEn])
    ->selectRaw("ts_headline('simple', coalesce(tasks.title_ar,'') || ' ' || coalesce(tasks.description_ar,'') || ' ' || coalesce(task_search_index.notes_ar,'') || ' ' || coalesce(task_search_index.comment_content_ar,''), to_tsquery('simple', ?), 'StartSel=<mark>,StopSel=</mark>,MaxWords=35,MinWords=15') as snippet_ar", [$tsqueryAr])
    ->selectRaw("ts_headline('english', coalesce(tasks.title_en,'') || ' ' || coalesce(tasks.description_en,'') || ' ' || coalesce(task_search_index.notes_en,'') || ' ' || coalesce(task_search_index.comment_content_en,''), to_tsquery('english', ?), 'StartSel=<mark>,StopSel=</mark>,MaxWords=35,MinWords=15') as snippet_en", [$tsqueryEn])
    ->where(function (Builder $q) use ($tsqueryAr, $tsqueryEn) {
        $q->whereRaw('tasks.search_vector_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
            ->orWhereRaw('tasks.search_vector_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn])
            ->orWhereRaw('task_search_index.search_vector_notes_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
            ->orWhereRaw('task_search_index.search_vector_notes_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn])
            ->orWhereRaw('task_search_index.search_vector_comments_ar @@ to_tsquery(\'simple\', ?)', [$tsqueryAr])
            ->orWhereRaw('task_search_index.search_vector_comments_en @@ to_tsquery(\'english\', ?)', [$tsqueryEn])
            ->orWhere('tasks.display_id', 'ilike', '%'.$filters['q'].'%');
    });
```

**Code snippet — SQLite fallback addition:**

```php
->orWhere('task_search_index.comment_content_ar', 'like', '%'.$q.'%')
->orWhere('task_search_index.comment_content_en', 'like', '%'.$q.'%')
```

**Code snippet — listener:**

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchIndexService;
use App\Modules\Task\Events\CommentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateSearchIndexOnCommentCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchIndexService $searchIndexService,
    ) {}

    public function handle(CommentCreated $event): void
    {
        $this->searchIndexService->upsertForTask($event->comment->task);
    }
}
```

**Test cases:**
1. Add comment with text "budget ceiling" → search `q=budget` returns the task.
2. Comment on confidential task by non-participant → search does not expose the task to that user (handled by `TaskVisibilityScope`).

**Rules:** `coding-standards.md` § Queues & Jobs (`ShouldQueue`, `$tries = 3`, `$backoff = [30,60,120]`), § Caching (search results are not cached).

---

### 10. Recent Activity Integration

**One-line summary:** Write `SearchActivityType::CommentAdded` rows when comments are created.

**Files:**
- `app/Modules/Search/Services/SearchActivityService.php`
- `app/Modules/Search/Listeners/RecordActivityOnCommentCreated.php`

**Code snippet — SearchActivityService method:**

```php
public function recordCommentAdded(User $user, Task $task): void
{
    $this->insert($user, $task, SearchActivityType::CommentAdded);
}
```

**Code snippet — listener:**

```php
<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Search\Services\SearchActivityService;
use App\Modules\Task\Events\CommentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordActivityOnCommentCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private SearchActivityService $activityService,
    ) {}

    public function handle(CommentCreated $event): void
    {
        $this->activityService->recordCommentAdded($event->user, $event->comment->task);
    }
}
```

**Rules:** `coding-standards.md` § Queues & Jobs, § Module Boundaries (Search maintains its own read model).

---

### 11. Routes

**One-line summary:** Register task-scoped comment routes and activate deferred document routes.

**Files:**
- `routes/api/v1/tasks.php`
- `routes/api/v1/documents.php`

**Code snippet — tasks.php additions:**

```php
use App\Modules\Task\Controllers\CommentController;

// inside the 'tasks' prefix group
Route::get('{task}/comments', [CommentController::class, 'index']);
Route::post('{task}/comments', [CommentController::class, 'store']);
```

**Code snippet — documents.php change:**

```php
Route::get('comments/{comment}/documents', [DocumentAttachmentController::class, 'listForComment']);
Route::post('comments/{comment}/documents', [DocumentAttachmentController::class, 'uploadForComment']);
```

**Rules:** `coding-standards.md` § Routes — versioned under `/api/v1/`, kebab-case.

---

### 12. Tests & Factory

**One-line summary:** Pest feature tests and a model factory.

**Files:**
- `database/factories/CommentFactory.php`
- `tests/Feature/Modules/Task/CommentTest.php`

**Code snippet — factory:**

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
```

**Test coverage (outline):**
- Authenticated user can create a top-level comment.
- Authenticated user can reply to a top-level comment.
- Reply to a reply returns 422.
- Reply to a comment on another task returns 422.
- User without task visibility gets 403.
- Comment list returns top-level comments with nested replies and `{data, next_cursor, has_more}`.
- Comment attachment upload/list works and respects `task.manage_documents` / `task.view_documents`.
- Search returns a task after a comment containing the query is added.
- Recent activity includes `CommentAdded` after comment creation.

**Rules:** `coding-standards.md` § Testing — feature tests mandatory for every endpoint.

---

## Execution Order

1. **Migrations** — run the comments table migration and the additive `task_search_index` migration.
2. **Task module foundation** — create `Comment` model, `StoreCommentRequest`, `InvalidCommentParentException`, `CommentService`, `CommentController`, `CommentResource`, `CommentCreated` event.
3. **Task relationships & routes** — add `Task::comments()` relation and register comment routes in `routes/api/v1/tasks.php`.
4. **Document module extension** — add comment methods to `DocumentService` and `DocumentAttachmentController`, then uncomment routes.
5. **Search module extension** — update `TaskSearchIndex` fillable, `SearchIndexService`, `SearchService`, `SearchActivityService`, and add the two queued listeners.
6. **Tests & factory** — create `CommentFactory` and `CommentTest`; run the suite.
7. **OpenAPI & lint** — regenerate `openapi/openapi.json` and run `vendor/bin/pint`.

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/v1/tasks/{task}/comments` | Sanctum + task visibility | Cursor-paginated top-level comments with nested replies |
| `POST` | `/api/v1/tasks/{task}/comments` | Sanctum + task visibility | Create a top-level comment or reply |
| `GET` | `/api/v1/comments/{comment}/documents` | Sanctum + `task.view_documents` + task visibility | Cursor-paginated documents attached to a comment |
| `POST` | `/api/v1/comments/{comment}/documents` | Sanctum + `task.manage_documents` + task visibility | Upload a document attached to a comment |

---

## What to Test Manually

1. **Happy path:** Create a task, add a top-level comment, reply to it, attach a PDF to the reply, and list comments to verify threading and attachment count.
2. **Authorization:** Log in as a user who cannot view a confidential task and confirm both `GET /tasks/{task}/comments` and `POST` return 403.
3. **Reply validation:** Try to reply to a reply and confirm 422 with "Parent must be a top-level comment."
4. **Cross-task reply:** Try to use a comment from task A as the parent for a comment on task B and confirm 422.
5. **Search integration:** Add a unique word to a comment, then call `GET /api/v1/search/tasks?q=<word>` and confirm the parent task appears.
6. **Recent activity:** Add a comment and confirm `GET /api/v1/search/recent` surfaces the task with `activity_type = CommentAdded`.
7. **Rate limiting:** Exceed 30 comment posts per minute and confirm 429 with `Retry-After`.
8. **Document upload limits:** Upload an oversized or disallowed file type to a comment and confirm 422.
9. **Cursor pagination:** Add 20+ top-level comments and verify `next_cursor` / `has_more` behavior.
10. **Audit trail:** Verify an `audit_events` row exists for `comment.created` with the task as root entity.
