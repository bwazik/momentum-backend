<?php

use App\Modules\Organization\Exceptions\AuthorityGradeHasActivePositionsException;
use App\Modules\Organization\Exceptions\CannotDeleteDefaultCalendarException;
use App\Modules\Organization\Exceptions\CircularDepartmentReferenceException;
use App\Modules\Organization\Exceptions\CircularReportingLineException;
use App\Modules\Organization\Exceptions\DepartmentHasActivePositionsException;
use App\Modules\Organization\Exceptions\DepartmentHasChildrenException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('v1*'),
        );

        $exceptions->renderable(
            fn (CircularDepartmentReferenceException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
        $exceptions->renderable(
            fn (CircularReportingLineException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
        $exceptions->renderable(
            fn (DepartmentHasActivePositionsException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
        $exceptions->renderable(
            fn (AuthorityGradeHasActivePositionsException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
        $exceptions->renderable(
            fn (CannotDeleteDefaultCalendarException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
        $exceptions->renderable(
            fn (DepartmentHasChildrenException $e) => response()->json(['message' => $e->getMessage()], 422)
        );
    })->create();
