<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintStage;
use App\Modules\Blueprint\Models\BlueprintSubStage;
use App\Modules\Blueprint\Requests\ReorderSubStagesRequest;
use App\Modules\Blueprint\Requests\StoreBlueprintSubStageRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintSubStageRequest;
use App\Modules\Blueprint\Resources\BlueprintSubStageResource;
use App\Modules\Blueprint\Services\BlueprintSubStageService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintSubStageController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintSubStageService $subStageService,
    ) {}

    public function index(Request $request, Blueprint $blueprint, BlueprintStage $stage)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);
        $subStages = $stage->subStages()->orderBy('sequence_order')->get();

        return BlueprintSubStageResource::collection($subStages);
    }

    public function store(StoreBlueprintSubStageRequest $request, Blueprint $blueprint, BlueprintStage $stage): BlueprintSubStageResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $subStage = $this->subStageService->create($blueprint, $stage, $request->validated());

        return new BlueprintSubStageResource($subStage);
    }

    public function update(UpdateBlueprintSubStageRequest $request, Blueprint $blueprint, BlueprintStage $stage, BlueprintSubStage $subStage): BlueprintSubStageResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $subStage = $this->subStageService->update($blueprint, $stage, $subStage, $request->validated());

        return new BlueprintSubStageResource($subStage);
    }

    public function destroy(Request $request, Blueprint $blueprint, BlueprintStage $stage, BlueprintSubStage $subStage): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->subStageService->delete($blueprint, $stage, $subStage);

        return response()->json(null, 204);
    }

    public function reorder(ReorderSubStagesRequest $request, Blueprint $blueprint, BlueprintStage $stage): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->subStageService->reorder($blueprint, $stage, $request->input('sub_stages'));

        return response()->json(null, 204);
    }
}
