<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Features\UserImpersonation;
use Stancl\Tenancy\Tenancy;

class ImpersonationService
{
    public function __construct(protected Tenancy $tenancy) {}

    /**
     * Start an impersonation session.
     */
    public function startImpersonation(Tenant $tenant, $userId, string $redirectUrl = '/'): string
    {
        // We log the impersonator explicitly.
        // Real audit log implementation would go here (Domain 14).
        $impersonatorId = Auth::id();

        session()->put('impersonator_id', $impersonatorId);
        session()->put('impersonated_tenant_slug', $tenant->slug);

        // Utilize the package's UserImpersonation macro to get a token
        // Requires Stancl\Tenancy\Features\UserImpersonation to be enabled in config
        $token = tenancy()->impersonate($tenant, $userId, $redirectUrl);

        return route('stancl.tenancy.impersonate', ['token' => $token->token]);
    }

    /**
     * Leave the impersonation session.
     */
    public function leaveImpersonation()
    {
        session()->forget('impersonator_id');
        session()->forget('impersonated_tenant_slug');

        // In stancl/tenancy v3, impersonation is just a standard Auth login.
        // To leave, we simply log the user out of the current tenant session.
        Auth::logout();
    }
}
