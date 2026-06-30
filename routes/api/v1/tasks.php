<?php

use App\Modules\Task\Controllers\CommentController;
use App\Modules\Task\Controllers\StageLifecycleController;
use App\Modules\Task\Controllers\TaskController;
use App\Modules\Task\Controllers\TaskPriorityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('tasks')->group(function () {
    // Task Priorities
    Route::get('priorities', [TaskPriorityController::class, 'index']);
    Route::middleware(['capability:task.manage_priorities'])->group(function () {
        Route::post('priorities', [TaskPriorityController::class, 'store']);
        Route::put('priorities/{priority}', [TaskPriorityController::class, 'update']);
        Route::post('priorities/{priority}/deactivate', [TaskPriorityController::class, 'deactivate']);
        Route::post('priorities/{priority}/reactivate', [TaskPriorityController::class, 'reactivate']);
    });

    // Tasks
    Route::get('/', [TaskController::class, 'index']);
    Route::post('/', [TaskController::class, 'store']);
    Route::get('{task}', [TaskController::class, 'show']);
    Route::put('{task}', [TaskController::class, 'update']);
    Route::delete('{task}', [TaskController::class, 'destroy']);

    // Tasks — Launch
    Route::post('{task}/launch', [TaskController::class, 'launch']);

    // Tasks — Lifecycle
    Route::middleware(['capability:task.suspend_resume'])->group(function () {
        Route::post('{task}/suspend', [TaskController::class, 'suspend']);
        Route::post('{task}/resume', [TaskController::class, 'resume']);
    });
    Route::middleware(['capability:task.cancel'])->group(function () {
        Route::post('{task}/cancel', [TaskController::class, 'cancel']);
    });

    // Stage Lifecycle
    Route::get('{task}/stages', [StageLifecycleController::class, 'stages']);
    Route::get('{task}/stages/{stageInstance}', [StageLifecycleController::class, 'showStage']);
    Route::post('{task}/stages/{stageInstance}/complete', [StageLifecycleController::class, 'completeStage']);
    Route::post('{task}/stages/{stageInstance}/return', [StageLifecycleController::class, 'returnStage']);
    Route::post('{task}/stages/{stageInstance}/override-assignment', [StageLifecycleController::class, 'overrideStageAssignment']);

    // Sub-stage Lifecycle
    Route::post('{task}/sub-stages/{subStageInstance}/complete', [StageLifecycleController::class, 'completeSubStage']);
    Route::post('{task}/sub-stages/{subStageInstance}/return', [StageLifecycleController::class, 'returnSubStage']);
    Route::post('{task}/sub-stages/{subStageInstance}/override-assignment', [StageLifecycleController::class, 'overrideSubStageAssignment']);

    // History & Timeline
    Route::get('{task}/returns', [StageLifecycleController::class, 'returns']);
    Route::get('{task}/timeline', [StageLifecycleController::class, 'timeline']);

    // Comments
    Route::get('{task}/comments', [CommentController::class, 'index']);
    Route::post('{task}/comments', [CommentController::class, 'store']);
});
