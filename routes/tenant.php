<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantStatus;
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

use App\Http\Middleware\InitializeTenancyByHeader;

Route::middleware([
    'api',
    InitializeTenancyByHeader::class,
    CheckTenantStatus::class,
])->prefix('api/v1')->group(function () {

    // Temporary test route to verify tenant isolation
    Route::get('test-tenancy', function () {
        return response()->json([
            'message' => 'Successfully connected to tenant database!',
            'tenant' => [
                'public_id' => tenant('public_id'),
                'slug' => tenant('slug'),
                'name' => tenant('name_en'),
            ],
            'database_name' => \Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
        ]);
    });

    Route::get('/', function () {
        return response()->json([
            'message' => 'This is your multi-tenant application.',
            'tenant_id' => tenant('public_id'),
        ]);
    });
});
