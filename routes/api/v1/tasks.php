<?php

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
});
