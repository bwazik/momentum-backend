<?php

use App\Exceptions\DomainException;
use App\Exceptions\ThrottleException;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequirePlatformAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules',
    ])
    ->withEvents(discover: [
        __DIR__.'/../app/Modules/*/Listeners',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'capability' => RequireCapability::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Api exceptions
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->is('v1*'));

        // Domain exceptions (all modules)
        $exceptions->renderable(fn (DomainException $e) => $e->render());

        // App exceptions
        $exceptions->renderable(fn (ThrottleException $e) => $e->render());
    })->create();
