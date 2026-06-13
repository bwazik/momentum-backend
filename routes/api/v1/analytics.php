<?php

use App\Modules\Analytics\Controllers\AgingReportController;
use App\Modules\Analytics\Controllers\DepartmentDashboardController;
use App\Modules\Analytics\Controllers\ExecutiveDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('analytics')->group(function () {
    Route::prefix('executive')->group(function () {
        Route::get('summary', [ExecutiveDashboardController::class, 'summary']);
        Route::get('bottlenecks', [ExecutiveDashboardController::class, 'bottlenecks']);
        Route::get('department-health', [ExecutiveDashboardController::class, 'departmentHealth']);
        Route::get('summary/drill-down/{metric}', [ExecutiveDashboardController::class, 'summaryDrillDown']);
        Route::get('bottlenecks/{stage_type}/drill-down', [ExecutiveDashboardController::class, 'bottleneckDrillDown']);
    });

    Route::prefix('departments/{department}')->group(function () {
        Route::get('performance', [DepartmentDashboardController::class, 'performance']);
        Route::get('team', [DepartmentDashboardController::class, 'team']);
        Route::get('performance/drill-down', [DepartmentDashboardController::class, 'drillDown']);
    });

    Route::get('tasks/aging', [AgingReportController::class, 'index']);
});
