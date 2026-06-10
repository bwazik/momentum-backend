<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Models\MonitoringScopeGrant;
use App\Modules\Iam\Requests\GrantMonitoringScopeRequest;
use App\Modules\Iam\Resources\MonitoringScopeGrantResource;
use App\Modules\Iam\Services\MonitoringScopeService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MonitoringScopeGrantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private MonitoringScopeService $monitoringScopeService,
    ) {}

    public function index(User $user): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        $grants = MonitoringScopeGrant::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('scopeDepartment')
            ->get();

        return MonitoringScopeGrantResource::collection($grants);
    }

    public function grant(GrantMonitoringScopeRequest $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $grant = $this->monitoringScopeService->grant($user, $request->validated(), $request->user());

        return response()->json(
            new MonitoringScopeGrantResource($grant->load('scopeDepartment')),
            201
        );
    }

    public function revoke(MonitoringScopeGrant $grant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $grant = $this->monitoringScopeService->revoke($grant);

        return response()->json(new MonitoringScopeGrantResource($grant));
    }
}
