<?php

namespace App\Http\Middleware;

use Closure;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByHeader
{
    public function __construct(protected Tenancy $tenancy) {}

    public function handle($request, Closure $next)
    {
        $header = config('tenancy.identification.header_key', 'X-Tenant');
        $tenantId = $request->header($header);
        if (! $tenantId) {
            abort(400, "Header $header is missing.");
        }

        if ($tenantId) {
            $tenant = config('tenancy.tenant_model')::where('public_id', $tenantId)->first();

            if ($tenant) {
                $this->tenancy->initialize($tenant);

                return $next($request);
            }
        }

        abort(404, 'Tenant could not be identified via header.');
    }
}
