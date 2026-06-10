<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Models\PositionCapabilityGrant;
use App\Modules\Iam\Requests\GrantPositionCapabilityRequest;
use App\Modules\Iam\Resources\PositionCapabilityGrantResource;
use App\Modules\Iam\Services\GrantService;
use App\Modules\Organization\Models\Position;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionCapabilityGrantController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private GrantService $grantService,
    ) {}

    public function index(Position $position): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        $grants = PositionCapabilityGrant::where('position_id', $position->id)
            ->whereNull('revoked_at')
            ->with('capability', 'scopeDepartment')
            ->get();

        return PositionCapabilityGrantResource::collection($grants);
    }

    public function grant(GrantPositionCapabilityRequest $request, Position $position): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $grant = $this->grantService->grantToPosition($position, $request->validated(), $request->user());

        return response()->json(
            new PositionCapabilityGrantResource($grant->load('capability', 'scopeDepartment')),
            201
        );
    }

    public function revoke(PositionCapabilityGrant $grant): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [request()->user()?->public_id ?? 'guest']);

        $grant = $this->grantService->revokePositionGrant($grant);

        return response()->json(new PositionCapabilityGrantResource($grant->load('capability')));
    }
}
