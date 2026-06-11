<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintTransition;
use App\Modules\Blueprint\Requests\StoreBlueprintTransitionRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintTransitionRequest;
use App\Modules\Blueprint\Resources\BlueprintTransitionResource;
use App\Modules\Blueprint\Services\BlueprintTransitionService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintTransitionController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintTransitionService $transitionService,
    ) {}

    public function index(Request $request, Blueprint $blueprint)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);
        $transitions = $blueprint->transitions()->with(['fromStage', 'toStage'])->get();

        return BlueprintTransitionResource::collection($transitions);
    }

    public function store(StoreBlueprintTransitionRequest $request, Blueprint $blueprint): BlueprintTransitionResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $transition = $this->transitionService->create($blueprint, $request->validated());

        return new BlueprintTransitionResource($transition);
    }

    public function update(UpdateBlueprintTransitionRequest $request, Blueprint $blueprint, BlueprintTransition $transition): BlueprintTransitionResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $transition = $this->transitionService->update($blueprint, $transition, $request->validated());

        return new BlueprintTransitionResource($transition);
    }

    public function destroy(Request $request, Blueprint $blueprint, BlueprintTransition $transition): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->transitionService->delete($blueprint, $transition);

        return response()->json(null, 204);
    }
}
