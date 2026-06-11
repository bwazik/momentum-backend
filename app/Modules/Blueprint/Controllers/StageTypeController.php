<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\StageType;
use App\Modules\Blueprint\Requests\StoreStageTypeRequest;
use App\Modules\Blueprint\Requests\UpdateStageTypeRequest;
use App\Modules\Blueprint\Resources\StageTypeResource;
use App\Modules\Blueprint\Services\StageTypeService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageTypeController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private StageTypeService $stageTypeService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        return StageTypeResource::collection($this->stageTypeService->getAll());
    }

    public function store(StoreStageTypeRequest $request): StageTypeResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stageType = $this->stageTypeService->create($request->validated());

        return new StageTypeResource($stageType);
    }

    public function update(UpdateStageTypeRequest $request, StageType $stageType): StageTypeResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $stageType = $this->stageTypeService->update($stageType, $request->validated());

        return new StageTypeResource($stageType);
    }

    public function destroy(Request $request, StageType $stageType): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->stageTypeService->delete($stageType);

        return response()->json(null, 204);
    }
}
