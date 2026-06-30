<?php

namespace App\Modules\Document\Services;

use App\Models\User;
use App\Modules\Document\Enums\DocumentEntityType;
use App\Modules\Document\Events\DocumentDeleted;
use App\Modules\Document\Events\DocumentDownloaded;
use App\Modules\Document\Events\DocumentPreviewed;
use App\Modules\Document\Events\DocumentUploaded;
use App\Modules\Document\Events\DocumentVersionCreated;
use App\Modules\Document\Exceptions\DocumentNotFoundException;
use App\Modules\Document\Exceptions\StorageProviderException;
use App\Modules\Document\Exceptions\UnsupportedPreviewTypeException;
use App\Modules\Document\Models\Document;
use App\Modules\Iam\Services\IamPolicy;
use App\Modules\Task\Models\Comment;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStageInstance;
use App\Modules\Task\Models\TaskSubStageInstance;
use App\Modules\Task\Scopes\TaskVisibilityScope;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentService
{
    public function __construct(
        private DocumentStorageService $storageService,
        private TaskVisibilityScope $taskVisibilityScope,
        private IamPolicy $iamPolicy,
    ) {}

    public function uploadForTask(Task $task, array $data, User $uploader): Document
    {
        $this->guardTaskVisibility(DocumentEntityType::Task, $task->id, $uploader);

        return $this->upload(DocumentEntityType::Task, $task->id, $data, $uploader);
    }

    public function uploadForStage(TaskStageInstance $stageInstance, array $data, User $uploader): Document
    {
        $task = $stageInstance->task;
        $this->guardTaskVisibility(DocumentEntityType::Task, $task->id, $uploader);

        return $this->upload(DocumentEntityType::StageOutput, $stageInstance->id, $data, $uploader);
    }

    public function uploadForSubStage(TaskSubStageInstance $subStageInstance, array $data, User $uploader): Document
    {
        $task = $subStageInstance->stageInstance->task;
        $this->guardTaskVisibility(DocumentEntityType::Task, $task->id, $uploader);

        return $this->upload(DocumentEntityType::StageOutput, $subStageInstance->id, $data, $uploader);
    }

    private function upload(DocumentEntityType $entityType, int $entityId, array $data, User $uploader): Document
    {
        $this->guardManageCapability($uploader);

        try {
            $file = $data['file'];
            $publicId = (string) Str::uuid7();

            $storagePath = $this->storageService->store($file, $publicId);

            $document = Document::create([
                'public_id' => $publicId,
                'uploader_user_id' => $uploader->id,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'version_number' => 1,
                'storage_path' => $storagePath,
                'description' => $data['description'] ?? null,
            ]);

            event(new DocumentUploaded($document));

            return $document;
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
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $uploader);
        $this->guardManageCapability($uploader);

        $publicId = (string) Str::uuid7();
        $storagePath = $this->storageService->store($file, $publicId);

        try {
            return DB::transaction(function () use ($document, $file, $uploader, $publicId, $storagePath) {
                $root = $document->root_document_id
                    ? Document::find($document->root_document_id)
                    : $document;

                if (! $root) {
                    throw new DocumentNotFoundException;
                }

                $version = Document::create([
                    'public_id' => $publicId,
                    'uploader_user_id' => $uploader->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'entity_type' => $document->entity_type,
                    'entity_id' => $document->entity_id,
                    'version_number' => max($root->version_number, $root->versions()->max('version_number') ?? 0) + 1,
                    'root_document_id' => $root->id,
                    'parent_document_id' => $document->id,
                    'storage_path' => $storagePath,
                    'description' => $document->description,
                ]);

                event(new DocumentVersionCreated($version, $document));

                return $version;
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

    public function uploadForComment(Comment $comment, array $data, User $uploader): Document
    {
        $this->guardTaskVisibility(DocumentEntityType::Comment, $comment->id, $uploader);

        return $this->upload(DocumentEntityType::Comment, $comment->id, $data, $uploader);
    }

    public function listForEntity(DocumentEntityType $entityType, int $entityId, User $user, int $perPage = 15): CursorPaginator
    {
        $this->guardTaskVisibility($entityType, $entityId, $user);

        return Document::currentVersions()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('uploader')
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
            ->with('uploader')
            ->orderBy('version_number')
            ->cursorPaginate($perPage);
    }

    public function delete(Document $document, User $user): void
    {
        $this->guardTaskVisibility($document->entity_type, $document->entity_id, $user);

        if ($document->uploader_user_id !== $user->id && ! $this->iamPolicy->hasCapability($user, 'task.manage_documents')) {
            abort(403, 'Only the uploader or a user with task.manage_documents can delete this document.');
        }

        $rootId = $document->root_document_id ?? $document->id;
        $rootPublicId = Document::where('id', $rootId)->value('public_id') ?? $document->public_id;

        try {
            DB::transaction(function () use ($document, $user, $rootId, $rootPublicId) {
                Document::where('id', $rootId)
                    ->orWhere('root_document_id', $rootId)
                    ->delete();

                event(new DocumentDeleted($document, $user, $rootPublicId));
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

        if ($document->storage_path === null) {
            throw new StorageProviderException;
        }

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

        if ($document->storage_path === null) {
            throw new StorageProviderException;
        }

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
        if (! $this->iamPolicy->hasCapability($user, 'task.view_documents')) {
            abort(403, 'Missing task.view_documents capability.');
        }

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
            DocumentEntityType::Comment => Comment::find($entityId)?->task,
            DocumentEntityType::HelpArticle => null,
        };
    }
}
