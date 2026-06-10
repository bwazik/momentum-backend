<?php

use App\Modules\Organization\Controllers\AuthorityGradeController;
use App\Modules\Organization\Controllers\DepartmentController;
use App\Modules\Organization\Controllers\PositionController;
use App\Modules\Organization\Controllers\PublicHolidayController;
use App\Modules\Organization\Controllers\WorkingCalendarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('organization')->group(function () {
        // Departments - read
        Route::prefix('departments')->group(function () {
            Route::get('/', [DepartmentController::class, 'index']);
            Route::get('tree', [DepartmentController::class, 'tree']);
            Route::get('{department}', [DepartmentController::class, 'show']);

            Route::middleware(['capability:organization.manage'])->group(function () {
                Route::post('/', [DepartmentController::class, 'store']);
                Route::put('{department}', [DepartmentController::class, 'update']);
                Route::post('{department}/deactivate', [DepartmentController::class, 'deactivate']);
                Route::post('{department}/reactivate', [DepartmentController::class, 'reactivate']);
                Route::delete('{department}', [DepartmentController::class, 'destroy']);
            });
        });

        // Authority Grades - read
        Route::prefix('authority-grades')->group(function () {
            Route::get('/', [AuthorityGradeController::class, 'index']);
            Route::get('{authorityGrade}', [AuthorityGradeController::class, 'show']);

            Route::middleware(['capability:organization.manage'])->group(function () {
                Route::post('/', [AuthorityGradeController::class, 'store']);
                Route::put('{authorityGrade}', [AuthorityGradeController::class, 'update']);
                Route::delete('{authorityGrade}', [AuthorityGradeController::class, 'destroy']);
            });
        });

        // Positions - read
        Route::prefix('positions')->group(function () {
            Route::get('/', [PositionController::class, 'index']);
            Route::get('{position}', [PositionController::class, 'show']);

            Route::middleware(['capability:organization.manage'])->group(function () {
                Route::post('/', [PositionController::class, 'store']);
                Route::put('{position}', [PositionController::class, 'update']);
                Route::post('{position}/transfer', [PositionController::class, 'transfer']);
                Route::post('{position}/deactivate', [PositionController::class, 'deactivate']);
                Route::post('{position}/reactivate', [PositionController::class, 'reactivate']);
                Route::delete('{position}', [PositionController::class, 'destroy']);
            });
        });

        // Working Calendars - read
        Route::prefix('working-calendars')->group(function () {
            Route::get('/', [WorkingCalendarController::class, 'index']);
            Route::get('{workingCalendar}', [WorkingCalendarController::class, 'show']);
            Route::get('{workingCalendar}/is-working-day', [WorkingCalendarController::class, 'isWorkingDay']);
            Route::get('{workingCalendar}/holidays', [PublicHolidayController::class, 'index']);
            Route::get('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'show']);

            Route::middleware(['capability:organization.manage'])->group(function () {
                Route::post('/', [WorkingCalendarController::class, 'store']);
                Route::put('{workingCalendar}', [WorkingCalendarController::class, 'update']);
                Route::delete('{workingCalendar}', [WorkingCalendarController::class, 'destroy']);
                Route::post('{workingCalendar}/holidays', [PublicHolidayController::class, 'store']);
                Route::put('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'update']);
                Route::delete('{workingCalendar}/holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy']);
            });
        });
    });
});
