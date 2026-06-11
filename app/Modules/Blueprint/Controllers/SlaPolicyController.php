<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\SlaPolicy;
use App\Modules\Blueprint\Requests\StoreSlaPolicyRequest;
use App\Modules\Blueprint\Requests\UpdateSlaPolicyRequest;
use App\Modules\Blueprint\Resources\SlaPolicyResource;
use App\Modules\Blueprint\Services\SlaPolicyService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlaPolicyController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private SlaPolicyService $slaPolicyService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        return SlaPolicyResource::collection($this->slaPolicyService->getAll());
    }

    public function store(StoreSlaPolicyRequest $request): SlaPolicyResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $slaPolicy = $this->slaPolicyService->create($request->validated());

        return new SlaPolicyResource($slaPolicy);
    }

    public function update(UpdateSlaPolicyRequest $request, SlaPolicy $slaPolicy): SlaPolicyResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $slaPolicy = $this->slaPolicyService->update($slaPolicy, $request->validated());

        return new SlaPolicyResource($slaPolicy);
    }

    public function destroy(Request $request, SlaPolicy $slaPolicy): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->slaPolicyService->delete($slaPolicy);

        return response()->json(null, 204);
    }
}
