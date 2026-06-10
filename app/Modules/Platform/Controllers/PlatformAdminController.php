<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Platform\Requests\StorePlatformAdminRequest;
use App\Modules\Platform\Requests\UpdatePlatformAdminRequest;
use App\Modules\Platform\Resources\PlatformAdminResource;
use App\Modules\Platform\Services\PlatformAdminService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAdminController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private PlatformAdminService $adminService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $admins = $this->adminService->list(
            $request->input('search'),
            (int) $request->input('per_page', 15),
        );

        $paginator = $admins->through(fn ($admin) => new PlatformAdminResource($admin));

        return response()->json(array_merge(
            $paginator->toArray(),
            ['has_more' => $paginator->hasMorePages()]
        ));
    }

    public function store(StorePlatformAdminRequest $request): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $admin = $this->adminService->create(
            $request->validated(),
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformAdminResource($admin), 201);
    }

    public function show(User $admin): JsonResponse
    {
        return response()->json(new PlatformAdminResource($admin));
    }

    public function update(UpdatePlatformAdminRequest $request, User $admin): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $admin = $this->adminService->update(
            $admin,
            $request->validated(),
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformAdminResource($admin));
    }

    public function deactivate(Request $request, User $admin): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $admin = $this->adminService->deactivate(
            $admin,
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformAdminResource($admin));
    }

    public function reactivate(Request $request, User $admin): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $admin = $this->adminService->reactivate(
            $admin,
            $request->user()->id,
            $request->ip(),
        );

        return response()->json(new PlatformAdminResource($admin));
    }
}
