<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->account_type !== AccountType::PLATFORM_ADMIN) {
            abort(403, 'This action requires platform administrator privileges.');
        }

        if (! $user->is_active || $user->deleted_at !== null) {
            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
