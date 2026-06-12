<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantStatus;
use App\Http\Middleware\InitializeTenancyByHeader;
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

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->prefix('v1')->group(function () {
    require __DIR__.'/api/v1/platform.php';
});

Route::middleware([
    InitializeTenancyByHeader::class,
    CheckTenantStatus::class,
    'api',
])->prefix('v1')->group(function () {

    Route::get('/', function () {
        return response()->json([
            'message' => 'This is your multi-tenant application.',
            'tenant' => [
                'public_id' => tenant('public_id'),
                'slug' => tenant('slug'),
                'name' => tenant('name_en'),
            ],
            'database_name' => DB::connection()->getDatabaseName(),
        ]);
    });

    require __DIR__.'/api/v1/organization.php';
    require __DIR__.'/api/v1/iam.php';
    require __DIR__.'/api/v1/blueprints.php';
    require __DIR__.'/api/v1/tasks.php';
});
