<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        Scramble::registerUiRoute('v1/docs/api');
        Scramble::registerJsonSpecificationRoute('v1/docs/api.json');
    }
}
