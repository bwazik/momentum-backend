<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Requests\ReorderStagesRequest;
use App\Modules\Blueprint\Requests\StoreBlueprintStageRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintStageRequest;
use App\Modules\Blueprint\Resources\BlueprintStageResource;
use App\Modules\Blueprint\Services\BlueprintStageService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintStageController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintStageService $stageService,
    ) {}

    public function index(Request $request, Blueprint $blueprint)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);
        $stages = $blueprint->stages()->orderBy('sequence_order')->get();

        return BlueprintStageResource::collection($stages);
    }

    public function store(StoreBlueprintStageRequest $request, Blueprint $blueprint): BlueprintStageResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stage = $this->stageService->create($blueprint, $request->validated());

        return new BlueprintStageResource($stage);
    }

    public function update(UpdateBlueprintStageRequest $request, Blueprint $blueprint, BlueprintStage $stage): BlueprintStageResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stage = $this->stageService->update($blueprint, $stage, $request->validated());

        return new BlueprintStageResource($stage);
    }

    public function destroy(Request $request, Blueprint $blueprint, BlueprintStage $stage): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->stageService->delete($blueprint, $stage);

        return response()->json(null, 204);
    }

    public function reorder(ReorderStagesRequest $request, Blueprint $blueprint): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->stageService->reorder($blueprint, $request->input('stages'));

        return response()->json(null, 204);
    }
}
