<?php

use App\Modules\Audit\Controllers\AuditTrailController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('tasks/{task}/audit-trail', [AuditTrailController::class, 'taskTrail'])
        ->name('audit.task-trail');

    Route::get('audit-trail/system', [AuditTrailController::class, 'systemLog'])
        ->name('audit.system-log');

    Route::get('audit-trail/me', [AuditTrailController::class, 'myActivity'])
        ->name('audit.my-activity');
});
