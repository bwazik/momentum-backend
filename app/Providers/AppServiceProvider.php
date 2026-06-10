<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Iam\Services\IamPolicy;
use Dedoc\Scramble\Scramble;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IamPolicy::class);

        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Route::bind('user', function (string $value): User {
            return User::withTrashed()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('admin', function (string $value): User {
            return User::withTrashed()->where('public_id', $value)->firstOrFail();
        });

        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $appNamespace = app()->getNamespace();

            if (str_starts_with($modelName, $appNamespace)) {
                $className = class_basename($modelName);

                return 'Database\\Factories\\'.$className.'Factory';
            }

            return 'Database\\Factories\\'.class_basename($modelName).'Factory';
        });

        app()->terminating(function () {
            app(IamPolicy::class)->clearCache();
        });

        Scramble::registerUiRoute('v1/docs/api');
        Scramble::registerJsonSpecificationRoute('v1/docs/api.json');
    }
}
