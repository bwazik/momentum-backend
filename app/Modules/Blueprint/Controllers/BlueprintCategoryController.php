<?php

namespace App\Modules\Blueprint\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Blueprint\Models\BlueprintCategory;
use App\Modules\Blueprint\Requests\StoreBlueprintCategoryRequest;
use App\Modules\Blueprint\Requests\UpdateBlueprintCategoryRequest;
use App\Modules\Blueprint\Resources\BlueprintCategoryResource;
use App\Modules\Blueprint\Services\BlueprintCategoryService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlueprintCategoryController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private BlueprintCategoryService $categoryService,
    ) {}

    public function index(Request $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()?->public_id ?? 'guest']);

        return BlueprintCategoryResource::collection($this->categoryService->getAll());
    }

    public function store(StoreBlueprintCategoryRequest $request): BlueprintCategoryResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $category = $this->categoryService->create($request->validated());

        return new BlueprintCategoryResource($category);
    }

    public function update(UpdateBlueprintCategoryRequest $request, BlueprintCategory $category): BlueprintCategoryResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $category = $this->categoryService->update($category, $request->validated());

        return new BlueprintCategoryResource($category);
    }

    public function deactivate(Request $request, BlueprintCategory $category): BlueprintCategoryResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $category = $this->categoryService->deactivate($category);

        return new BlueprintCategoryResource($category);
    }

    public function reactivate(Request $request, BlueprintCategory $category): BlueprintCategoryResource
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $category = $this->categoryService->reactivate($category);

        return new BlueprintCategoryResource($category);
    }

    public function destroy(Request $request, BlueprintCategory $category): JsonResponse
    {
        $this->checkRateLimit(RateLimits::MUTATE, [$request->user()->public_id]);
        $this->categoryService->delete($category);

        return response()->json(null, 204);
    }
}
