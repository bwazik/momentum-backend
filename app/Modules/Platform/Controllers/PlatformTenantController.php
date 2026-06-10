<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\RunTenantMigrationsJob;
use App\Models\Tenant;
use App\Modules\Platform\Requests\StoreTenantRequest;
use App\Modules\Platform\Requests\UpdateTenantRequest;
use App\Modules\Platform\Resources\PlatformTenantResource;
use App\Modules\Platform\Services\PlatformTenantService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformTenantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PlatformTenantService $tenantService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $paginator = $this->tenantService->list(
            $request->input('search'),
            $request->has('is_active') ? $request->boolean('is_active') : null,
            (int) $request->input('per_page', 15),
        )->through(fn ($tenant) => new PlatformTenantResource($tenant));

        return response()->json(array_merge(
            $paginator->toArray(),
            ['has_more' => $paginator->hasMorePages()]
        ));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $tenant = $this->tenantService->provision(
            $request->validated(),
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformTenantResource($tenant), 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(new PlatformTenantResource($tenant));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $tenant = $this->tenantService->update(
            $tenant,
            $request->validated(),
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformTenantResource($tenant));
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $tenant = $this->tenantService->suspend(
            $tenant,
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformTenantResource($tenant));
    }

    public function reactivate(Request $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $tenant = $this->tenantService->reactivate(
            $tenant,
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformTenantResource($tenant));
    }

    public function runMigrations(Request $request, Tenant $tenant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        RunTenantMigrationsJob::dispatch(
            $tenant,
            $request->user()->id,
            $request->user()->public_id,
            $request->ip(),
        );

        return response()->json(['message' => 'Migrations dispatched. Check audit events for status.'], 202);
    }
}
