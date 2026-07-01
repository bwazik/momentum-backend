<?php

use App\Modules\Iam\Controllers\AuditGrantController;
use App\Modules\Iam\Controllers\AuthController;
use App\Modules\Iam\Controllers\CapabilityController;
use App\Modules\Iam\Controllers\DelegationController;
use App\Modules\Iam\Controllers\MonitoringScopeGrantController;
use App\Modules\Iam\Controllers\PositionAssignmentController;
use App\Modules\Iam\Controllers\PositionCapabilityGrantController;
use App\Modules\Iam\Controllers\UserCapabilityGrantController;
use App\Modules\Iam\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('iam')->group(function () {

    // Auth (no auth required for login)
    Route::post('auth/login', [AuthController::class, 'login']);

    // Authenticated routes
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Users
        Route::middleware(['capability:iam.manage_users'])->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
        });

        // Self-service (user can update own profile)
        Route::get('profile', [UserController::class, 'profile']);
        Route::put('profile', [UserController::class, 'updateProfile']);

        // Position Assignments
        Route::middleware(['capability:iam.manage_positions'])->group(function () {
            Route::post('users/{user}/positions', [PositionAssignmentController::class, 'assign']);
            Route::post('users/{user}/positions/{assignment}/end', [PositionAssignmentController::class, 'end']);
            Route::post('users/{user}/positions/{assignment}/set-primary', [PositionAssignmentController::class, 'setPrimary']);
        });

        // Capabilities (read-only catalog for all authenticated users)
        Route::get('capabilities', [CapabilityController::class, 'index']);
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('capabilities/{capability}', [CapabilityController::class, 'show']);
            Route::put('capabilities/{capability}', [CapabilityController::class, 'update']);
        });

        // Position Capability Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('positions/{position}/capabilities', [PositionCapabilityGrantController::class, 'index']);
            Route::post('positions/{position}/capabilities', [PositionCapabilityGrantController::class, 'grant']);
            Route::post('position-capability-grants/{grant}/revoke', [PositionCapabilityGrantController::class, 'revoke']);
        });

        // User Capability Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('users/{user}/capabilities', [UserCapabilityGrantController::class, 'index']);
            Route::post('users/{user}/capabilities', [UserCapabilityGrantController::class, 'grant']);
            Route::post('user-capability-grants/{grant}/revoke', [UserCapabilityGrantController::class, 'revoke']);
        });

        // Monitoring Scope Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('users/{user}/monitoring-scopes', [MonitoringScopeGrantController::class, 'index']);
            Route::post('users/{user}/monitoring-scopes', [MonitoringScopeGrantController::class, 'grant']);
            Route::post('monitoring-scope-grants/{grant}/revoke', [MonitoringScopeGrantController::class, 'revoke']);
        });

        // Delegations
        Route::middleware(['capability:iam.manage_users|iam.view_delegations'])->group(function () {
            Route::get('delegations', [DelegationController::class, 'index']);
            Route::get('delegations/active', [DelegationController::class, 'active']);
            Route::get('delegations/{delegation}', [DelegationController::class, 'show']);
        });

        Route::middleware(['capability:iam.manage_users'])->group(function () {
            Route::post('delegations', [DelegationController::class, 'store']);
            Route::put('delegations/{delegation}', [DelegationController::class, 'update']);
            Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);
        });

        // Audit Grants
        Route::middleware(['capability:iam.manage_capabilities'])->group(function () {
            Route::get('users/{user}/audit-grants', [AuditGrantController::class, 'index']);
            Route::post('users/{user}/audit-grants', [AuditGrantController::class, 'grant']);
            Route::post('audit-grants/{grant}/revoke', [AuditGrantController::class, 'revoke']);
        });

        // Out-of-office (self or admin)
        Route::post('users/{user}/out-of-office', [UserController::class, 'markOutOfOffice']);
        Route::post('users/{user}/back-in-office', [UserController::class, 'markBackInOffice']);
    });
});
