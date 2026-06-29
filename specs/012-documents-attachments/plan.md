# Plan: Documents & Attachments

> **Spec:** 012-documents-attachments
> **Date:** 2026-06-29
> **Status:** completed

---

## Open Questions Resolved

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default storage disk | **Laravel `Storage` facade with configured disk.** Default `local` for MVP; S3/MinIO enabled only by changing `FILESYSTEM_DISK` env / `config/filesystems.php`. No direct SDK calls. | Keeps the code filesystem-agnostic so MVP runs on the VPS disk and later swaps to object storage without code changes. |
| Max upload file size | **20 MB default**, overridable per tenant in `tenants.settings.max_upload_size_mb`. | Covers PDFs, Office docs, and images; large media/video deferred. |
| Allowed MIME types | **PDF, JPG/JPEG, PNG, GIF, DOC/DOCX, XLS/XLSX.** Reject executables, archives, and unknown types. Configurable per tenant in `tenants.settings.allowed_mime_types`. | Matches feature inventory #176; tenant admin can tighten/relax. |
| Physical deletion on soft-delete | **Retain object-storage file on soft-delete.** Add a future garbage-collection job (out of scope). | Preserves audit trail and allows soft-delete restore. |
| Download/preview delivery | **Stream through the API** via `Storage::readStream()` so ABAC is enforced on every request. Presigned URLs deferred to V2. | Confidential-task attachments must not be accessible via direct public URL. |
| `description` or `display_name` column | **Add nullable `description` text column.** | UI clarity without losing `original_filename` for audit. |

---

## Technical Approach

Create a new `Document` module under `app/Modules/Document/` that owns the `documents` tenant table, a filesystem-agnostic `DocumentStorageService`, a `DocumentService` for metadata/versioning, and two controllers (`DocumentAttachmentController` for entity-scoped uploads/lists, `DocumentController` for generic operations). All file paths are tenant-prefixed; all reads enforce parent-task visibility via the existing `TaskVisibilityScope`; all mutations emit domain events consumed by Audit (Spec 015).

**Key decisions:**
- **Filesystem-agnostic storage layer** — every storage operation goes through Laravel `Storage` facade so the disk driver can be `local`, `s3`, or `MinIO` without code changes.
- **Linear versioning with `root_document_id`** — versions are linked rows; listing endpoints return only current versions (`whereNull('root_document_id')` + `latestVersion`); history endpoints query by root.
- **Visibility via parent task** — Document module calls `TaskVisibilityScope` on the task associated with the attachment; no document-specific ACL in MVP.
- **Capability gating** — `task.manage_documents` required for upload/replace/delete; `task.view_documents` is granted to all internal users by default but is still filtered by task visibility.
- **No queued jobs in MVP** — uploads/downloads are synchronous; virus scanning/thumbnails deferred.

---

## Affected Modules / Files

### New Files (to create)

| File | Purpose |
|------|---------|
| **Enums** | |
| `app/Modules/Document/Enums/DocumentEntityType.php` | Task, Comment, StageOutput, HelpArticle |
| `app/Modules/Document/Enums/DocumentMimeCategory.php` | Pdf, Image, Word, Excel, Other |
| **Migration** | |
| `database/migrations/tenant/2026_06_29_000001_create_documents_table.php` | `documents` tenant table |
| **Models** | |
| `app/Modules/Document/Models/Document.php` | Document metadata + version relationships |
| **Services** | |
| `app/Modules/Document/Services/DocumentStorageService.php` | Filesystem-agnostic upload/read/delete via `Storage` facade |
| `app/Modules/Document/Services/DocumentService.php` | Metadata CRUD, versioning, visibility checks |
| **Controllers** | |
| `app/Modules/Document/Controllers/DocumentAttachmentController.php` | Entity-scoped upload/list endpoints |
| `app/Modules/Document/Controllers/DocumentController.php` | Show, download, preview, version, delete |
| **Requests** | |
| `app/Modules/Document/Requests/UploadDocumentRequest.php` | Validate `file` and optional `description` |
| `app/Modules/Document/Requests/UploadDocumentVersionRequest.php` | Validate replacement `file` |
| **Resources** | |
| `app/Modules/Document/Resources/DocumentResource.php` | Document metadata JSON shape |
| `app/Modules/Document/Resources/DocumentVersionResource.php` | Version history JSON shape |
| **Events** | |
| `app/Modules/Document/Events/DocumentUploaded.php` | New attachment metadata persisted |
| `app/Modules/Document/Events/DocumentVersionCreated.php` | New version created |
| `app/Modules/Document/Events/DocumentDownloaded.php` | File downloaded |
| `app/Modules/Document/Events/DocumentPreviewed.php` | File previewed inline |
| `app/Modules/Document/Events/DocumentDeleted.php` | Document soft-deleted |
| **Exceptions** | |
| `app/Modules/Document/Exceptions/DocumentNotFoundException.php` | 404 |
| `app/Modules/Document/Exceptions/UnsupportedPreviewTypeException.php` | 422 |
| `app/Modules/Document/Exceptions/StorageProviderException.php` | 500 |
| **Routes** | |
| `routes/api/v1/documents.php` | All document routes |
| **Tests** | |
| `tests/Feature/Modules/Document/DocumentUploadTest.php` | Upload, validation, visibility |
| `tests/Feature/Modules/Document/DocumentDownloadTest.php` | Download, preview, auth |
| `tests/Feature/Modules/Document/DocumentVersionTest.php` | Versioning chain |

### Modified Files (to edit)

| File | Change |
|------|--------|
| `config/logging.php` | Add `document` logging channel |
| `routes/tenant.php` | `require __DIR__.'/api/v1/documents.php';` |
| `database/seeders/CapabilitySeeder.php` | Add `task.manage_documents` and `task.view_documents` |
| `database/seeders/TenantDatabaseSeeder.php` | No change required (no default rows for documents) |

---

## Implementation Notes

### 1. Enums

**One-line summary:** Two int-backed enums in `app/Modules/Document/Enums/`, used in models, form requests, and services.

**Key decisions:**
- `DocumentEntityType` covers all polymorphic owners; `HelpArticle = 4` is reserved for Spec 020.
- `DocumentMimeCategory` groups MIME types for preview eligibility and validation messaging.

**Files:**
- `app/Modules/Document/Enums/DocumentEntityType.php`
- `app/Modules/Document/Enums/DocumentMimeCategory.php`

**Code snippet — `DocumentEntityType`:**
```php
<?php

namespace App\Modules\Document\Enums;

enum DocumentEntityType: int
{
    case Task = 1;
    case Comment = 2;
    case StageOutput = 3;
    case HelpArticle = 4;
}
```

**Code snippet — `DocumentMimeCategory`:**
```php
<?php

namespace App\Modules\Document\Enums;

enum DocumentMimeCategory: int
{
    case Pdf = 1;
    case Image = 2;
    case Word = 3;
    case Excel = 4;
    case Other = 5;

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            $mimeType === 'application/pdf' => self::Pdf,
            in_array($mimeType, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ], true) => self::Word,
            in_array($mimeType, [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true) => self::Excel,
            default => self::Other,
        };
    }

    public function supportsPreview(): bool
    {
        return in_array($this, [self::Pdf, self::Image], true);
    }
}
```

**Test cases:**
1. `DocumentMimeCategory::fromMimeType('application/pdf')` → `DocumentMimeCategory::Pdf`
2. `DocumentMimeCategory::fromMimeType('image/png')->supportsPreview()` → `true`

**Rules:** `coding-standards.md` — Enum Usage. Use `Rule::enum()` in Form Requests; no magic integers in services.

---

### 2. Migration

**One-line summary:** Single tenant migration creating the polymorphic `documents` table with versioning and soft deletes.

**Key decisions:**
- `entity_type` stored as TINYINT mapped to `DocumentEntityType`.
- `root_document_id` and `parent_document_id` enable linear version chains.
- `description` added for UI clarity.
- Composite index on `(entity_type, entity_id)` for fast polymorphic lookups.

**File:** `database/migrations/tenant/2026_06_29_000001_create_documents_table.php`

**Code snippet:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('uploader_user_id')->constrained('users');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedTinyInteger('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->foreignId('root_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['root_document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

**Rules:** `coding-standards.md` — Migrations. No `tenant_id`; tenant-prefixed storage paths provide isolation.

---

### 3. Model

**One-line summary:** `Document` extends `TenantModel`, casts enums, defines self-referential version relationships.

**Key decisions:**
- `currentVersions()` scope returns only the latest version of each chain for listing.
- `versions()` returns all rows in the same version chain.
- `latestVersion()` returns the highest `version_number` for a root document.

**File:** `app/Modules/Document/Models/Document.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Document\Models;

use App\Models\TenantModel;
use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Enums\DocumentMimeCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'uploader_user_id', 'original_filename', 'storage_path', 'mime_type',
    'size_bytes', 'entity_type', 'entity_id', 'version_number',
    'root_document_id', 'parent_document_id', 'description',
])]
class Document extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'entity_type' => DocumentEntityType::class,
            'size_bytes' => 'integer',
            'version_number' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'root_document_id')->orderBy('version_number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(self::class, 'root_document_id')->latest('version_number');
    }

    public function scopeCurrentVersions($query)
    {
        return $query->whereNull('root_document_id');
    }

    public function mimeCategory(): DocumentMimeCategory
    {
        return DocumentMimeCategory::fromMimeType($this->mime_type);
    }
}
```

**Test cases:**
1. `Document::factory()->create(['root_document_id' => null])->currentVersions()->exists()` → `true`
2. `$root->latestVersion->version_number` → highest version number in chain

**Rules:** `coding-standards.md` — Models. Use `casts()` method; no `tenant_id` column.

---

### 4. DocumentStorageService

**One-line summary:** Thin wrapper around Laravel `Storage` facade with tenant-prefixed paths; keeps the rest of the module filesystem-agnostic.

**Key decisions:**
- Uses `config('filesystems.default')` disk.
- Path format: `{tenant_slug}/documents/{document_public_id}/{hashName}`.
- Stores original filename separately in DB.
- Does not delete physical file on soft-delete (future GC job).

**File:** `app/Modules/Document/Services/DocumentStorageService.php`

**Code snippet:**
```php
<?php

namespace App\Modules\Document\Services;

use App\Modules\Document\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentStorageService
{
    public function store(UploadedFile $file, string $documentPublicId): string
    {
        $tenantSlug = tenant()?->slug ?? 'central';
        $path = "{$tenantSlug}/documents/{$documentPublicId}";

        try {
            return $file->store($path, config('filesystems.default'));
        } catch (\Throwable $e) {
            Log::channel('document')->error('Failed to store file', [
                'tenant_slug' => $tenantSlug,
                'action' => 'document.store',
                'document_id' => $documentPublicId,
                'error' => $e->getMessage(),
            ]);
            throw new \App\Modules\Document\Exceptions\StorageProviderException;
        }
    }

    public function readStream(string $storagePath)
    {
        try {
            return Storage::disk(config('filesystems.default'))->readStream($storagePath);
        } catch (\Throwable $e) {
            Log::channel('document')->error('Failed to read file stream', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'document.read_stream',
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
            throw new \App\Modules\Document\Exceptions\StorageProviderException;
        }
    }

    public function exists(string $storagePath): bool
    {
        return Storage::disk(config('filesystems.default'))->exists($storagePath);
    }
}
```

**Test cases:**
1. `store($uploadedFile, 'doc-public-id')` returns path containing `tenant-slug/documents/doc-public-id/`.
2. `readStream('missing/path.pdf')` throws `StorageProviderException`.

**Rules:** `coding-standards.md` — Error Handling (try/catch + module channel); no direct SDK calls.

---

### 5. DocumentService

**One-line summary:** Business layer for attachment metadata, versioning, visibility enforcement, and event emission.

**Key decisions:**
- Injects `TaskVisibilityScope` to enforce parent-task visibility on every read.
- `upload()` creates a root document row, stores the file, and emits `DocumentUploaded`.
- `createVersion()` creates a successor row inside `DB::transaction()`, stores new file, emits `DocumentVersionCreated`.
- `delete()` soft-deletes the entire version chain inside `DB::transaction()`.
- `stream()` returns a Symfony `StreamedResponse` with correct `Content-Disposition`.

**File:** `app/Modules/Document/Services/DocumentService.php`

**Code snippet — constructor & helpers:**
```php
<?php

namespace App\Modules\Document\Services;

use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Enums\DocumentMimeCategory;
use App\Modules\Document\Events\DocumentDeleted;
use App\Modules\Document\Events\DocumentDownloaded;
use App\Modules\Document\Events\DocumentPreviewed;
use App\Modules\Document\Events\DocumentUploaded;
use App\Modules\Document\Events\DocumentVersionCreated;
use App\Modules\Document\Exceptions\DocumentNotFoundException;
use App\Modules\Document\Exceptions\UnsupportedPreviewTypeException;
use App\Modules\Document\Models\Document;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use App\Traits\AuthenticatedUser;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DocumentService
{
    use AuthenticatedUser;

    public function __construct(
        private DocumentStorageService $storageService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    /**
     * @param  array{file: UploadedFile, description?: string}  $data
     */
    public function uploadForTask(Task $task, array $data, User $uploader): Document
    {
        return $this->upload(DocumentEntityType::Task, $task->id, $data, $uploader);
    }

    public function uploadForStage(TaskStageInstance $stageInstance, array $data, User $uploader): Document
    {
        return $this->upload(DocumentEntityType::StageOutput, $stageInstance->id, $data, $uploader);
    }

    public function uploadForSubStage(TaskSubStageInstance $subStageInstance, array $data, User $uploader): Document
    {
        return $this->upload(DocumentEntityType::StageOutput, $subStageInstance->id, $data, $uploader);
    }

    /**
     * @param  array{file: UploadedFile, description?: string}  $data
     */
    private function upload(DocumentEntityType $entityType, int $entityId, array $data, User $uploader): Document
    {
        $this->guardManageCapability($uploader);

        try {
            $file = $data['file'];

            $document = Document::create([
                'uploader_user_id' => $uploader->id,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version_number' => 1,
                'description' => $data['description'] ?? null,
            ]);

            $storagePath = $this->storageService->store($file, $document->public_id);
            $document->update(['storage_path' => $storagePath]);

            event(new DocumentUploaded($document));

            return $document->fresh();
        } catch (\Throwable $e) {
            Log::channel('document')->error('Failed to upload document', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'document.upload',
                'entity_type' => $entityType->name,
                'entity_id' => $entityId,
                'performed_by' => $uploader->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createVersion(Document $document, UploadedFile $file, User $uploader): Document
    {
        $this->guardManageCapability($uploader);

        try {
            return DB::transaction(function () use ($document, $file, $uploader) {
                $root = $document->root_document_id
                    ? Document::find($document->root_document_id)
                    : $document;

                if (! $root) {
                    throw new DocumentNotFoundException;
                }

                $version = Document::create([
                    'uploader_user_id' => $uploader->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'entity_type' => $document->entity_type,
                    'entity_id' => $document->entity_id,
                    'version_number' => $root->versions()->max('version_number') + 1,
                    'root_document_id' => $root->id,
                    'parent_document_id' => $document->id,
                    'description' => $document->description,
                ]);

                $storagePath = $this->storageService->store($file, $version->public_id);
                $version->update(['storage_path' => $storagePath]);

                event(new DocumentVersionCreated($version, $document));

                return $version->fresh();
            });
        } catch (DocumentNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('document')->error('Failed to create document version', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'document.version.create',
                'entity_type' => $document->entity_type->name,
                'entity_id' => $document->entity_id,
                'document_id' => $document->public_id,
                'performed_by' => $uploader->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function listForEntity(DocumentEntityType $entityType, int $entityId, User $user, int $perPage = 15): CursorPaginator
    {
        $this->guardTaskVisibility($entityType, $entityId, $user);

        return Document::currentVersions()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('latestVersion.uploader')
            ->orderBy('id')
            ->cursorPaginate($perPage);
    }

    public function show(Document $document, User $user): Document
    {
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $user);

        return $document->load('uploader');
    }

    public function versions(Document $document, User $user, int $perPage = 15): CursorPaginator
    {
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $user);

        $rootId = $document->root_document_id ?? $document->id;

        return Document::where('id', $rootId)
            ->orWhere('root_document_id', $rootId)
            ->orderBy('version_number')
            ->cursorPaginate($perPage);
    }

    public function delete(Document $document, User $user): void
    {
        $this->guardManageCapability($user);

        if ($document->uploader_user_id !== $user->id && ! $this->iamPolicy->hasCapability($user, 'task.manage_documents')) {
            abort(403, 'Only the uploader or a user with task.manage_documents can delete this document.');
        }

        try {
            DB::transaction(function () use ($document) {
                $rootId = $document->root_document_id ?? $document->id;

                Document::where('id', $rootId)
                    ->orWhere('root_document_id', $rootId)
                    ->delete();

                event(new DocumentDeleted($document));
            });
        } catch (\Throwable $e) {
            Log::channel('document')->error('Failed to delete document', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'document.delete',
                'entity_type' => $document->entity_type->name,
                'entity_id' => $document->entity_id,
                'document_id' => $document->public_id,
                'performed_by' => $user->public_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function download(Document $document, User $user): array
    {
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $user);

        $stream = $this->storageService->readStream($document->storage_path);

        event(new DocumentDownloaded($document, $user));

        return [
            'stream' => $stream,
            'filename' => $document->original_filename,
            'mimeType' => $document->mime_type,
        ];
    }

    public function preview(Document $document, User $user): array
    {
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $user);

        if (! $document->mimeCategory()->supportsPreview()) {
            throw new UnsupportedPreviewTypeException;
        }

        $stream = $this->storageService->readStream($document->storage_path);

        event(new DocumentPreviewed($document, $user));

        return [
            'stream' => $stream,
            'mimeType' => $document->mime_type,
        ];
    }

    private function guardTaskVisibility(DocumentEntityType $entityType, int $entityId, User $user): void
    {
        $task = $this->resolveTask($entityType, $entityId);

        if (! $task) {
            abort(404, 'Parent entity not found.');
        }

        $visible = $this->taskVisibilityScope
            ->apply(Task::query()->where('id', $task->id), $user)
            ->exists();

        if (! $visible) {
            abort(403, 'You do not have access to the parent task.');
        }
    }

    private function guardManageCapability(User $user): void
    {
        if (! $this->iamPolicy->hasCapability($user, 'task.manage_documents')) {
            abort(403, 'Missing task.manage_documents capability.');
        }
    }

    private function resolveTask(DocumentEntityType $entityType, int $entityId): ?Task
    {
        return match ($entityType) {
            DocumentEntityType::Task => Task::find($entityId),
            DocumentEntityType::StageOutput => TaskStageInstance::find($entityId)?->task,
            DocumentEntityType::Comment => null, // Spec 013 will provide Comment model
            DocumentEntityType::HelpArticle => null, // Spec 020
        };
    }
}
```

**Test cases:**
1. Upload PDF to task → `Document` row created with `version_number = 1`, file stored at tenant-prefixed path.
2. Create version of document → new row with `version_number = 2`, `root_document_id = root.id`, `parent_document_id = previous.id`.

**Rules:** `coding-standards.md` — Database Transactions (version, delete), Error Handling (try/catch + `Log::channel('document')`), Events (`ShouldDispatchAfterCommit`), Module Boundaries (Document calls Task visibility scope; no cross-module joins).

---

### 6. Controllers

**One-line summary:** Two thin controllers use `HasRateLimiting`, validate requests, and delegate to `DocumentService`.

**Key decisions:**
- `DocumentAttachmentController` handles entity-scoped uploads/lists.
- `DocumentController` handles generic show/download/preview/version/delete.
- Download/preview return streamed Symfony responses.

**Files:**
- `app/Modules/Document/Controllers/DocumentAttachmentController.php`
- `app/Modules/Document/Controllers/DocumentController.php`

**Code snippet — `DocumentAttachmentController`:**
```php
<?php

namespace App\Modules\Document\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Requests\UploadDocumentRequest;
use App\Modules\Document\Resources\DocumentResource;
use App\Modules\Document\Services\DocumentService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\Request;

class DocumentAttachmentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DocumentService $documentService,
    ) {}

    public function listForTask(Request $request, Task $task)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return DocumentResource::collection(
            $this->documentService->listForEntity(\App\Modules\Document\Enums\DocumentEntityType::Task, $task->id, $request->user(), $request->integer('per_page', 15))
        );
    }

    public function uploadForTask(UploadDocumentRequest $request, Task $task): DocumentResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $document = $this->documentService->uploadForTask($task, $request->validated(), $request->user());

        return new DocumentResource($document);
    }

    public function listForStage(Request $request, TaskStageInstance $stageInstance)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return DocumentResource::collection(
            $this->documentService->listForEntity(\App\Modules\Document\Enums\DocumentEntityType::StageOutput, $stageInstance->id, $request->user(), $request->integer('per_page', 15))
        );
    }

    public function uploadForStage(UploadDocumentRequest $request, TaskStageInstance $stageInstance): DocumentResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $document = $this->documentService->uploadForStage($stageInstance, $request->validated(), $request->user());

        return new DocumentResource($document);
    }

    public function listForSubStage(Request $request, TaskSubStageInstance $subStageInstance)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return DocumentResource::collection(
            $this->documentService->listForEntity(\App\Modules\Document\Enums\DocumentEntityType::StageOutput, $subStageInstance->id, $request->user(), $request->integer('per_page', 15))
        );
    }

    public function uploadForSubStage(UploadDocumentRequest $request, TaskSubStageInstance $subStageInstance): DocumentResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $document = $this->documentService->uploadForSubStage($subStageInstance, $request->validated(), $request->user());

        return new DocumentResource($document);
    }
}
```

**Code snippet — `DocumentController`:**
```php
<?php

namespace App\Modules\Document\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Models\Document;
use App\Modules\Document\Requests\UploadDocumentVersionRequest;
use App\Modules\Document\Resources\DocumentResource;
use App\Modules\Document\Resources\DocumentVersionResource;
use App\Modules\Document\Services\DocumentService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DocumentService $documentService,
    ) {}

    public function show(Request $request, Document $document): DocumentResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new DocumentResource($this->documentService->show($document, $request->user()));
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $file = $this->documentService->download($document, $request->user());

        return response()->stream(function () use ($file) {
            fpassthru($file['stream']);
        }, 200, [
            'Content-Type' => $file['mimeType'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    public function preview(Request $request, Document $document): StreamedResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $file = $this->documentService->preview($document, $request->user());

        return response()->stream(function () use ($file) {
            fpassthru($file['stream']);
        }, 200, [
            'Content-Type' => $file['mimeType'],
            'Content-Disposition' => 'inline; filename="' . $document->original_filename . '"',
        ]);
    }

    public function createVersion(UploadDocumentVersionRequest $request, Document $document): DocumentResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $version = $this->documentService->createVersion($document, $request->file('file'), $request->user());

        return new DocumentResource($version);
    }

    public function versions(Request $request, Document $document)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return DocumentVersionResource::collection(
            $this->documentService->versions($document, $request->user(), $request->integer('per_page', 15))
        );
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->documentService->delete($document, $request->user());

        return response()->json(null, 204);
    }
}
```

**Rules:** `coding-standards.md` — Controllers (thin), Rate Limiting (`HasRateLimiting` trait, `RateLimits` constants), API Resources.

---

### 7. Form Requests

**One-line summary:** Validate uploads and versions; `authorize()` returns `true` (ABAC in service).

**Files:**
- `app/Modules/Document/Requests/UploadDocumentRequest.php`
- `app/Modules/Document/Requests/UploadDocumentVersionRequest.php`

**Code snippet — `UploadDocumentRequest`:**
```php
<?php

namespace App\Modules\Document\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxMb = tenant()?->settings['max_upload_size_mb'] ?? 20;

        return [
            'file' => [
                'required',
                File::types([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->max($maxMb * 1024),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

**Code snippet — `UploadDocumentVersionRequest`:**
```php
<?php

namespace App\Modules\Document\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxMb = tenant()?->settings['max_upload_size_mb'] ?? 20;

        return [
            'file' => [
                'required',
                File::types([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->max($maxMb * 1024),
            ],
        ];
    }
}
```

**Rules:** `coding-standards.md` — Validation (Form Request classes); no inline validation in controllers.

---

### 8. API Resources

**One-line summary:** Expose `public_id` only; include download/preview URL helpers.

**Files:**
- `app/Modules/Document/Resources/DocumentResource.php`
- `app/Modules/Document/Resources/DocumentVersionResource.php`

**Code snippet — `DocumentResource`:**
```php
<?php

namespace App\Modules\Document\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'mime_category' => $this->mimeCategory()->name,
            'size_bytes' => $this->size_bytes,
            'version_number' => $this->version_number,
            'description' => $this->description,
            'uploader' => [
                'public_id' => $this->uploader?->public_id,
                'name_ar' => $this->uploader?->name_ar,
                'name_en' => $this->uploader?->name_en,
            ],
            'download_url' => route('documents.download', $this->public_id),
            'preview_url' => $this->mimeCategory()->supportsPreview()
                ? route('documents.preview', $this->public_id)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Code snippet — `DocumentVersionResource`:**
```php
<?php

namespace App\Modules\Document\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'version_number' => $this->version_number,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploader' => [
                'public_id' => $this->uploader?->public_id,
                'name_ar' => $this->uploader?->name_ar,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Rules:** `coding-standards.md` — API Resources (`public_id` only, never internal `id`).

---

### 9. Events

**One-line summary:** Five domain events, all implement `ShouldDispatchAfterCommit`.

**Files:**
- `app/Modules/Document/Events/DocumentUploaded.php`
- `app/Modules/Document/Events/DocumentVersionCreated.php`
- `app/Modules/Document/Events/DocumentDownloaded.php`
- `app/Modules/Document/Events/DocumentPreviewed.php`
- `app/Modules/Document/Events/DocumentDeleted.php`

**Code snippet — `DocumentUploaded`:**
```php
<?php

namespace App\Modules\Document\Events;

use App\Models\User;
use App\Modules\Document\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentUploaded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Document $document,
    ) {}
}
```

**Code snippet — `DocumentDownloaded` (auditable):**
```php
<?php

namespace App\Modules\Document\Events;

use App\Models\User;
use App\Modules\Document\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentDownloaded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Document $document,
        public User $user,
    ) {}
}
```

**Rules:** `coding-standards.md` — Domain Events (`ShouldDispatchAfterCommit` non-negotiable).

---

### 10. Exceptions

**One-line summary:** Three domain exceptions extending `App\Exceptions\DomainException`; auto-registered by existing `bootstrap/app.php` handler.

**Files:**
- `app/Modules/Document/Exceptions/DocumentNotFoundException.php` (404)
- `app/Modules/Document/Exceptions/UnsupportedPreviewTypeException.php` (422)
- `app/Modules/Document/Exceptions/StorageProviderException.php` (500)

**Code snippet — `DocumentNotFoundException`:**
```php
<?php

namespace App\Modules\Document\Exceptions;

use App\Exceptions\DomainException;

class DocumentNotFoundException extends DomainException
{
    protected int $statusCode = 404;

    public function __construct()
    {
        parent::__construct('Document not found.');
    }
}
```

**Code snippet — `UnsupportedPreviewTypeException`:**
```php
<?php

namespace App\Modules\Document\Exceptions;

use App\Exceptions\DomainException;

class UnsupportedPreviewTypeException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This file type cannot be previewed inline.');
    }
}
```

**Rules:** `coding-standards.md` — Error Handling (domain exceptions extend base class).

---

### 11. Routes

**One-line summary:** New route file `routes/api/v1/documents.php` registered in `routes/tenant.php`.

**Key decisions:**
- Entity-scoped routes use explicit controller methods for Task/Stage/Sub-stage.
- Comment routes are commented out until Spec 013 provides the `Comment` model.
- Generic `/documents/{document}` routes use named routes for URL generation in resources.

**File:** `routes/api/v1/documents.php`

**Code snippet:**
```php
<?php

use App\Modules\Document\Controllers\DocumentAttachmentController;
use App\Modules\Document\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Task attachments
    Route::get('tasks/{task}/documents', [DocumentAttachmentController::class, 'listForTask']);
    Route::post('tasks/{task}/documents', [DocumentAttachmentController::class, 'uploadForTask']);

    // Stage / sub-stage output attachments
    Route::get('task-stage-instances/{stageInstance}/documents', [DocumentAttachmentController::class, 'listForStage']);
    Route::post('task-stage-instances/{stageInstance}/documents', [DocumentAttachmentController::class, 'uploadForStage']);
    Route::get('task-sub-stage-instances/{subStageInstance}/documents', [DocumentAttachmentController::class, 'listForSubStage']);
    Route::post('task-sub-stage-instances/{subStageInstance}/documents', [DocumentAttachmentController::class, 'uploadForSubStage']);

    // Comment attachments — uncomment after Spec 013 creates Comment model
    // Route::get('comments/{comment}/documents', [DocumentAttachmentController::class, 'listForComment']);
    // Route::post('comments/{comment}/documents', [DocumentAttachmentController::class, 'uploadForComment']);

    // Generic document operations
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
    Route::post('documents/{document}/versions', [DocumentController::class, 'createVersion'])->name('documents.versions.create');
    Route::get('documents/{document}/versions', [DocumentController::class, 'versions'])->name('documents.versions');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
});
```

**Modified file:** `routes/tenant.php` — add `require __DIR__.'/api/v1/documents.php';` inside the tenant route group.

**Rules:** `coding-standards.md` — Routes (kebab-case, versioned under `/api/v1/`).

---

### 12. Config & Seeding

**Logging channel:** Add to `config/logging.php` channels array:
```php
'document' => [
    'driver' => 'daily',
    'path' => storage_path('logs/document/document.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
    'replace_placeholders' => true,
],
```

**Capabilities:** Add to `database/seeders/CapabilitySeeder.php` `CAPABILITIES` array:
```php
['key' => 'task.manage_documents', 'name_ar' => 'إدارة مرفقات المهام', 'name_en' => 'Manage Task Documents', 'description' => 'Can upload, replace, and delete attachments on visible tasks.'],
['key' => 'task.view_documents', 'name_ar' => 'عرض مرفقات المهام', 'name_en' => 'View Task Documents', 'description' => 'Can view and download attachments for visible tasks.'],
```

**Rules:** `coding-standards.md` — Logging (module-specific channel), no magic capabilities.

---

## Execution Order

1. Update `specs/012-documents-attachments/spec.md` to mark storage open question resolved (done).
2. Create enums `DocumentEntityType` and `DocumentMimeCategory`.
3. Create tenant migration `create_documents_table`.
4. Create `Document` model with relationships/scopes.
5. Create `DocumentStorageService` (filesystem-agnostic wrapper).
6. Create `DocumentService` with upload, version, list, show, delete, download, preview.
7. Create domain events and exceptions.
8. Create Form Requests and API Resources.
9. Create `DocumentAttachmentController` and `DocumentController`.
10. Create `routes/api/v1/documents.php` and register it in `routes/tenant.php`.
11. Add `document` channel to `config/logging.php`.
12. Add capabilities to `database/seeders/CapabilitySeeder.php`.
13. Create feature tests and factories.
14. Run migrations, seeders, and tests.
15. Regenerate `openapi/openapi.json` when contract is marked stable (after review).

---

## API Contract Summary

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/v1/tasks/{task}/documents` | Sanctum + `task.manage_documents` | Upload attachment to task |
| GET | `/api/v1/tasks/{task}/documents` | Sanctum + task visibility | List current task attachments (cursor) |
| POST | `/api/v1/task-stage-instances/{stageInstance}/documents` | Sanctum + `task.manage_documents` | Upload stage output attachment |
| GET | `/api/v1/task-stage-instances/{stageInstance}/documents` | Sanctum + task visibility | List stage output attachments (cursor) |
| POST | `/api/v1/task-sub-stage-instances/{subStageInstance}/documents` | Sanctum + `task.manage_documents` | Upload sub-stage output attachment |
| GET | `/api/v1/task-sub-stage-instances/{subStageInstance}/documents` | Sanctum + task visibility | List sub-stage output attachments (cursor) |
| POST | `/api/v1/comments/{comment}/documents` | Sanctum + `task.manage_documents` | Upload comment attachment (Spec 013) |
| GET | `/api/v1/comments/{comment}/documents` | Sanctum + task visibility | List comment attachments (Spec 013) |
| GET | `/api/v1/documents/{document}` | Sanctum + task visibility | Show document metadata |
| GET | `/api/v1/documents/{document}/download` | Sanctum + task visibility | Download file (attachment disposition) |
| GET | `/api/v1/documents/{document}/preview` | Sanctum + task visibility | Preview file inline (PDF/image only) |
| POST | `/api/v1/documents/{document}/versions` | Sanctum + `task.manage_documents` | Upload new version |
| GET | `/api/v1/documents/{document}/versions` | Sanctum + task visibility | List version history (cursor) |
| DELETE | `/api/v1/documents/{document}` | Sanctum + uploader or `task.manage_documents` | Soft-delete document chain |

---

## What to Test Manually

1. **Happy path upload:** Upload a PDF to a task; verify metadata response includes `public_id`, `download_url`, `preview_url`.
2. **Happy path version:** Upload a new version of the PDF; verify `version_number = 2` and both versions appear in `/versions`.
3. **Download & preview:** Download returns correct filename; preview returns `Content-Disposition: inline` for PDF/image.
4. **Visibility enforcement:** User A uploads to a confidential task; User B without access gets 403 on download/list.
5. **Capability enforcement:** User without `task.manage_documents` gets 403 on upload.
6. **Validation:** Upload a 30 MB file or `.exe` → 422.
7. **Unsupported preview:** Upload `.docx` and call `/preview` → 422.
8. **Soft-delete chain:** Delete a document with two versions; both rows get `deleted_at`; object-storage file remains.
9. **Storage swap:** Change `FILESYSTEM_DISK` to a MinIO/S3 disk and repeat upload/download without code changes.
10. **Rate limiting:** Fire 31 upload requests in one minute → 429 on the 31st.
11. **Cursor pagination:** Create 30 attachments; verify `next_cursor`/`has_more` shape.
12. **Tenant isolation:** Upload in tenant A; tenant B cannot see the file via path guessing.
