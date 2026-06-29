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
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
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
            'Content-Disposition' => 'inline; filename="'.$document->original_filename.'"',
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

        $perPage = min(100, max(1, $request->integer('per_page', 15)));
        $paginator = $this->documentService->versions($document, $request->user(), $perPage)
            ->through(fn ($doc) => new DocumentVersionResource($doc));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);

        $this->documentService->delete($document, $request->user());

        return response()->json(null, 204);
    }
}
