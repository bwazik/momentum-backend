<?php

use App\Modules\Blueprint\Controllers\BlueprintCategoryController;
use App\Modules\Blueprint\Controllers\BlueprintController;
use App\Modules\Blueprint\Controllers\BlueprintStageController;
use App\Modules\Blueprint\Controllers\BlueprintSubStageController;
use App\Modules\Blueprint\Controllers\BlueprintTransitionController;
use App\Modules\Blueprint\Controllers\SlaPolicyController;
use App\Modules\Blueprint\Controllers\StageTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('blueprints')->group(function () {
    // Categories
    Route::get('categories', [BlueprintCategoryController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('categories', [BlueprintCategoryController::class, 'store']);
        Route::put('categories/{category}', [BlueprintCategoryController::class, 'update']);
        Route::delete('categories/{category}', [BlueprintCategoryController::class, 'destroy']);
        Route::post('categories/{category}/deactivate', [BlueprintCategoryController::class, 'deactivate']);
        Route::post('categories/{category}/reactivate', [BlueprintCategoryController::class, 'reactivate']);
    });

    // Stage Types
    Route::get('stage-types', [StageTypeController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('stage-types', [StageTypeController::class, 'store']);
        Route::put('stage-types/{stageType}', [StageTypeController::class, 'update']);
        Route::delete('stage-types/{stageType}', [StageTypeController::class, 'destroy']);
    });

    // SLA Policies
    Route::get('sla-policies', [SlaPolicyController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('sla-policies', [SlaPolicyController::class, 'store']);
        Route::put('sla-policies/{slaPolicy}', [SlaPolicyController::class, 'update']);
        Route::delete('sla-policies/{slaPolicy}', [SlaPolicyController::class, 'destroy']);
    });

    // Blueprints
    Route::get('/', [BlueprintController::class, 'index']);
    Route::get('{blueprint}', [BlueprintController::class, 'show']);
    Route::middleware(['capability:blueprint.create.organization,blueprint.create.department'])->group(function () {
        Route::post('/', [BlueprintController::class, 'store']);
    });
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::put('{blueprint}', [BlueprintController::class, 'update']);
        Route::delete('{blueprint}', [BlueprintController::class, 'destroy']);
        Route::post('{blueprint}/activate', [BlueprintController::class, 'activate']);
        Route::post('{blueprint}/deactivate', [BlueprintController::class, 'deactivate']);
        Route::post('{blueprint}/duplicate', [BlueprintController::class, 'duplicate']);
    });

    // Stages
    Route::get('{blueprint}/stages', [BlueprintStageController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('{blueprint}/stages', [BlueprintStageController::class, 'store']);
        Route::put('{blueprint}/stages/{stage}', [BlueprintStageController::class, 'update']);
        Route::delete('{blueprint}/stages/{stage}', [BlueprintStageController::class, 'destroy']);
        Route::post('{blueprint}/stages/reorder', [BlueprintStageController::class, 'reorder']);
    });

    // Sub-stages
    Route::get('{blueprint}/stages/{stage}/sub-stages', [BlueprintSubStageController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('{blueprint}/stages/{stage}/sub-stages', [BlueprintSubStageController::class, 'store']);
        Route::put('{blueprint}/stages/{stage}/sub-stages/{subStage}', [BlueprintSubStageController::class, 'update']);
        Route::delete('{blueprint}/stages/{stage}/sub-stages/{subStage}', [BlueprintSubStageController::class, 'destroy']);
        Route::post('{blueprint}/stages/{stage}/sub-stages/reorder', [BlueprintSubStageController::class, 'reorder']);
    });

    // Transitions
    Route::get('{blueprint}/transitions', [BlueprintTransitionController::class, 'index']);
    Route::middleware(['capability:blueprint.manage'])->group(function () {
        Route::post('{blueprint}/transitions', [BlueprintTransitionController::class, 'store']);
        Route::put('{blueprint}/transitions/{transition}', [BlueprintTransitionController::class, 'update']);
        Route::delete('{blueprint}/transitions/{transition}', [BlueprintTransitionController::class, 'destroy']);
    });
});
