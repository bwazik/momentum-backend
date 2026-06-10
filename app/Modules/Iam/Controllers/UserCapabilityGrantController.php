<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Iam\Models\UserCapabilityGrant;
use App\Modules\Iam\Requests\GrantUserCapabilityRequest;
use App\Modules\Iam\Resources\EffectiveCapabilityResource;
use App\Modules\Iam\Resources\UserCapabilityGrantResource;
use App\Modules\Iam\Services\GrantService;
use App\Modules\Iam\Services\IamPolicy;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserCapabilityGrantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private GrantService $grantService,
        private IamPolicy $policy,
    ) {}

    public function index(User $user): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        $effectiveCapabilities = $this->policy->getEffectiveCapabilities($user);

        return EffectiveCapabilityResource::collection($effectiveCapabilities);
    }

    public function grant(GrantUserCapabilityRequest $request, User $user): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $grant = $this->grantService->grantToUser($user, $request->validated(), $request->user());

        return response()->json(
            new UserCapabilityGrantResource($grant->load('capability', 'scopeDepartment')),
            201
        );
    }

    public function revoke(UserCapabilityGrant $grant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $grant = $this->grantService->revokeUserGrant($grant);

        return response()->json(new UserCapabilityGrantResource($grant->load('capability')));
    }
}
