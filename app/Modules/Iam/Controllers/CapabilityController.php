<?php

namespace App\Modules\Iam\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Iam\Models\Capability;
use App\Modules\Iam\Requests\UpdateCapabilityRequest;
use App\Modules\Iam\Resources\CapabilityResource;
use App\Modules\Iam\Services\CapabilityService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CapabilityController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private CapabilityService $capabilityService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return CapabilityResource::collection(Capability::orderBy('key')->get());
    }

    public function show(Capability $capability): JsonResponse
    {
        $this->checkRateLimit(RateLimits::LIST, [request()->user()?->public_id ?? 'guest']);

        return response()->json(new CapabilityResource($capability));
    }

    public function update(UpdateCapabilityRequest $request, Capability $capability): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()?->public_id ?? 'guest']);

        $capability = $this->capabilityService->update($capability, $request->validated());

        return response()->json(new CapabilityResource($capability));
    }
}
