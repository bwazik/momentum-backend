<?php

use App\Modules\Document\Controllers\DocumentAttachmentController;
use App\Modules\Document\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
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
