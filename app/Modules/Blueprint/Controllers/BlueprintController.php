<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Requests\StoreBlueprintRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintRequest;
use App\Modules\Blueprint\Resources\BlueprintResource;
use App\Modules\Blueprint\Services\BlueprintService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintService $blueprintService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        $query = Blueprint::with('category');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('scope')) {
            $query->where('scope', $request->integer('scope'));
        }
        if ($request->has('category_id')) {
            $categoryId = BlueprintCategory::where('public_id', $request->input('category_id'))->value('id');
            $query->where('category_id', $categoryId);
        }

        return BlueprintResource::collection($query->cursorPaginate($request->integer('per_page', 20)));
    }

    public function show(Blueprint $blueprint): BlueprintResource
    {
        $blueprint->load(['stages.subStages', 'transitions']);

        return new BlueprintResource($blueprint);
    }

    public function store(StoreBlueprintRequest $request): BlueprintResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $blueprint = $this->blueprintService->create($request->validated());

        return new BlueprintResource($blueprint);
    }

    public function update(UpdateBlueprintRequest $request, Blueprint $blueprint): BlueprintResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $blueprint = $this->blueprintService->update($blueprint, $request->validated());

        return new BlueprintResource($blueprint);
    }

    public function activate(Request $request, Blueprint $blueprint): BlueprintResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $blueprint = $this->blueprintService->activate($blueprint);

        return new BlueprintResource($blueprint);
    }

    public function deactivate(Request $request, Blueprint $blueprint): BlueprintResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $blueprint = $this->blueprintService->deactivate($blueprint);

        return new BlueprintResource($blueprint);
    }

    public function duplicate(Request $request, Blueprint $blueprint): BlueprintResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $duplicate = $this->blueprintService->duplicate($blueprint, $request->user());

        return new BlueprintResource($duplicate);
    }

    public function destroy(Request $request, Blueprint $blueprint): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->blueprintService->delete($blueprint);

        return response()->json(null, 204);
    }
}
