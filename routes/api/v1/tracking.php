<?php

use App\Modules\Tracking\Controllers\EscalationController;
use App\Modules\Tracking\Controllers\SlaTimerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('tracking')->group(function () {
    Route::prefix('sla')->group(function () {
        Route::get('tasks/{task}', [SlaTimerController::class, 'taskHealth']);
        Route::get('timers', [SlaTimerController::class, 'index']);
    });

    Route::prefix('escalations')->group(function () {
        Route::get('/', [EscalationController::class, 'index']);
        Route::get('{escalation}', [EscalationController::class, 'show']);
        Route::middleware(['capability:task.escalate'])->group(function () {
            Route::post('/', [EscalationController::class, 'store']);
        });
        Route::post('{escalation}/resolve', [EscalationController::class, 'resolve']);
    });
});
