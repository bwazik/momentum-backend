<?php

namespace App\Modules\Document\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Enums\DocumentEntityType;
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

        $perPage = min(100, max(1, $request->integer('per_page', 15)));
        $paginator = $this->documentService->listForEntity(DocumentEntityType::Task, $task->id, $request->user(), $perPage)
            ->through(fn ($doc) => new DocumentResource($doc));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
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

        $perPage = min(100, max(1, $request->integer('per_page', 15)));
        $paginator = $this->documentService->listForEntity(DocumentEntityType::StageOutput, $stageInstance->id, $request->user(), $perPage)
            ->through(fn ($doc) => new DocumentResource($doc));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
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

        $perPage = min(100, max(1, $request->integer('per_page', 15)));
        $paginator = $this->documentService->listForEntity(DocumentEntityType::StageOutput, $subStageInstance->id, $request->user(), $perPage)
            ->through(fn ($doc) => new DocumentResource($doc));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function uploadForSubStage(UploadDocumentRequest $request, TaskSubStageInstance $subStageInstance): DocumentResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $document = $this->documentService->uploadForSubStage($subStageInstance, $request->validated(), $request->user());

        return new DocumentResource($document);
    }
}
