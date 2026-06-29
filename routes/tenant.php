<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\InitializeTenancyByHeader;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware(['api'])->prefix('v1')->group(function () {
    require __DIR__.'/api/v1/platform.php';
});

Route::middleware([
    InitializeTenancyByHeader::class,
    CheckTenantStatus::class,
    'api',
])->prefix('v1')->group(function () {
    Route::get('/', function () {
        $tenant = tenancy()->tenant;

        return response()->json([
            'tenant' => [
                'public_id' => $tenant->public_id,
                'name_ar' => $tenant->name_ar,
                'name_en' => $tenant->name_en ?? $tenant->name_ar,
            ],
        ]);
    });

    require __DIR__.'/api/v1/organization.php';
    require __DIR__.'/api/v1/iam.php';
    require __DIR__.'/api/v1/blueprints.php';
    require __DIR__.'/api/v1/tasks.php';
    require __DIR__.'/api/v1/tracking.php';
    require __DIR__.'/api/v1/notifications.php';
    require __DIR__.'/api/v1/analytics.php';
    require __DIR__.'/api/v1/follow-up.php';
    require __DIR__.'/api/v1/search.php';
    require __DIR__.'/api/v1/documents.php';
});
