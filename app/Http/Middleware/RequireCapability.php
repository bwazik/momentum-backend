<?php

namespace App\Http\Middleware;

use App\Modules\Iam\Services\IamPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCapability
{
    public function __construct(private IamPolicy $policy) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->isTenantAdmin()) {
            return $next($request);
        }

        if (! $this->policy->check($user, $capability)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
