<?php

namespace App\Modules\Document\Services;

use App\Modules\Document\Exceptions\StorageProviderException;
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
            throw new StorageProviderException;
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
            throw new StorageProviderException;
        }
    }

    public function exists(string $storagePath): bool
    {
        return Storage::disk(config('filesystems.default'))->exists($storagePath);
    }
}
