<?php

use App\Exceptions\ThrottleException;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Modules\Blueprint\Exceptions\BlueprintCategoryInUseException;
use App\Modules\Blueprint\Exceptions\BlueprintLockedException;
use App\Modules\Blueprint\Exceptions\InvalidBlueprintScopeException;
use App\Modules\Blueprint\Exceptions\InvalidStageSequenceException;
use App\Modules\Blueprint\Exceptions\InvalidTransitionException;
use App\Modules\Blueprint\Exceptions\SlaPolicyInUseException;
use App\Modules\Blueprint\Exceptions\StageTypeInUseException;
use App\Modules\Blueprint\Exceptions\UnauthorizedBlueprintScopeException;
use App\Modules\Iam\Exceptions\CannotDelegateToSelfException;
use App\Modules\Iam\Exceptions\CannotRevokeSystemCapabilityKeyException;
use App\Modules\Iam\Exceptions\DuplicateGrantException;
use App\Modules\Iam\Exceptions\PrimaryPositionAlreadyAssignedException;
use App\Modules\Iam\Exceptions\UserAlreadyActiveException;
use App\Modules\Iam\Exceptions\UserAlreadyDeactivatedException;
use App\Modules\Organization\Exceptions\AuthorityGradeHasActivePositionsException;
use App\Modules\Organization\Exceptions\CannotDeleteDefaultCalendarException;
use App\Modules\Organization\Exceptions\CircularDepartmentReferenceException;
use App\Modules\Organization\Exceptions\CircularReportingLineException;
use App\Modules\Organization\Exceptions\DepartmentHasActivePositionsException;
use App\Modules\Organization\Exceptions\DepartmentHasChildrenException;
use App\Modules\Platform\Exceptions\CannotImpersonatePlatformAdminException;
use App\Modules\Platform\Exceptions\CannotImpersonateSelfException;
use App\Modules\Platform\Exceptions\PlatformAdminCannotDeactivateSelfException;
use App\Modules\Platform\Exceptions\TenantAlreadyActiveException;
use App\Modules\Platform\Exceptions\TenantAlreadySuspendedException;
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
    ->withEvents(discover: [
        __DIR__.'/../app/Modules/*/Listeners',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'capability' => RequireCapability::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Api exceptions
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->is('v1*'));

        // Organization exceptions
        $exceptions->renderable(fn (CircularDepartmentReferenceException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (CircularReportingLineException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (DepartmentHasActivePositionsException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (AuthorityGradeHasActivePositionsException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (CannotDeleteDefaultCalendarException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (DepartmentHasChildrenException $e) => response()->json(['message' => $e->getMessage()], 422));

        // App exceptions
        $exceptions->renderable(fn (ThrottleException $e) => $e->render());

        // Blueprint exceptions
        $exceptions->renderable(fn (BlueprintLockedException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (InvalidStageSequenceException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (InvalidTransitionException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (SlaPolicyInUseException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (StageTypeInUseException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (BlueprintCategoryInUseException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (InvalidBlueprintScopeException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (UnauthorizedBlueprintScopeException $e) => response()->json(['message' => $e->getMessage()], 422));

        // IAM exceptions
        $exceptions->renderable(fn (CannotDelegateToSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (CannotRevokeSystemCapabilityKeyException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (DuplicateGrantException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (PrimaryPositionAlreadyAssignedException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (UserAlreadyActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (UserAlreadyDeactivatedException $e) => response()->json(['message' => $e->getMessage()], 422));

        // Platform exceptions
        $exceptions->renderable(fn (TenantAlreadySuspendedException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (TenantAlreadyActiveException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (CannotImpersonateSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (CannotImpersonatePlatformAdminException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->renderable(fn (PlatformAdminCannotDeactivateSelfException $e) => response()->json(['message' => $e->getMessage()], 422));
    })->create();
