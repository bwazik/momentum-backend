<?php

use App\Modules\FollowUp\Controllers\FollowUpActionController;
use App\Modules\FollowUp\Controllers\FollowUpBoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('follow-up')->group(function () {
    Route::get('board', [FollowUpBoardController::class, 'board']);
    Route::get('overdue', [FollowUpBoardController::class, 'overdue']);
    Route::get('at-risk', [FollowUpBoardController::class, 'atRisk']);
    Route::get('bottlenecks', [FollowUpBoardController::class, 'bottlenecks']);

    Route::get('actions', [FollowUpActionController::class, 'recent']);

    Route::prefix('tasks/{task}')->group(function () {
        Route::get('actions', [FollowUpActionController::class, 'index']);
        Route::post('actions', [FollowUpActionController::class, 'store']);
    });
});
