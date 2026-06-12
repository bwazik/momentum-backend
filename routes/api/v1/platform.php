<?php

use App\Modules\Platform\Controllers\PlatformAdminController;
use App\Modules\Platform\Controllers\PlatformAuditEventController;
use App\Modules\Platform\Controllers\PlatformAuthController;
use App\Modules\Platform\Controllers\PlatformImpersonationController;
use App\Modules\Platform\Controllers\PlatformTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform/auth')->group(function () {
    Route::post('login', [PlatformAuthController::class, 'login']);
    Route::post('logout', [PlatformAuthController::class, 'logout'])
        ->middleware('auth:sanctum');
    Route::get('me', [PlatformAuthController::class, 'me'])
        ->middleware(['auth:sanctum', 'platform.admin']);
});

Route::prefix('platform')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('admins', [PlatformAdminController::class, 'index']);
    Route::post('admins', [PlatformAdminController::class, 'store']);
    Route::get('admins/{admin}', [PlatformAdminController::class, 'show']);
    Route::put('admins/{admin}', [PlatformAdminController::class, 'update']);
    Route::post('admins/{admin}/deactivate', [PlatformAdminController::class, 'deactivate']);
    Route::post('admins/{admin}/reactivate', [PlatformAdminController::class, 'reactivate']);

    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::post('tenants', [PlatformTenantController::class, 'store']);
    Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show']);
    Route::put('tenants/{tenant}', [PlatformTenantController::class, 'update']);
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
    Route::post('tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate']);
    Route::post('tenants/{tenant}/run-migrations', [PlatformTenantController::class, 'runMigrations']);

    Route::post('tenants/{tenant}/impersonate', [PlatformImpersonationController::class, 'start']);
    Route::post('tenants/{tenant}/leave-impersonation', [PlatformImpersonationController::class, 'leave']);
    Route::get('impersonation-sessions', [PlatformImpersonationController::class, 'activeSessions']);

    Route::get('audit-events', [PlatformAuditEventController::class, 'index']);
});
