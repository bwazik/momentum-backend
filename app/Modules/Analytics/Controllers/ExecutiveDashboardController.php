<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\BottleneckRequest;
use App\Modules\Analytics\Requests\ExecutiveSummaryRequest;
use App\Modules\Analytics\Resources\BottleneckResource;
use App\Modules\Analytics\Resources\DepartmentHealthResource;
use App\Modules\Analytics\Resources\ExecutiveSummaryResource;
use App\Modules\Analytics\Resources\TaskListItemResource;
use App\Modules\Analytics\Services\ExecutiveDashboardService;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class ExecutiveDashboardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private ExecutiveDashboardService $service,
    ) {}

    public function summary(ExecutiveSummaryRequest $request): ExecutiveSummaryResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new ExecutiveSummaryResource(
            $this->service->summary($request->user(), $request->validated())
        );
    }

    public function bottlenecks(BottleneckRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->bottlenecks($request->user(), $request->validated());

        return BottleneckResource::collection($items);
    }

    public function departmentHealth(ExecutiveSummaryRequest $request)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->departmentHealth($request->user(), $request->validated());

        return DepartmentHealthResource::collection($items);
    }

    public function summaryDrillDown(ExecutiveSummaryRequest $request, string $metric)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->drillDown($request->user(), $metric, $request->validated())
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    public function bottleneckDrillDown(BottleneckRequest $request, string $stageType)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $filters = array_merge($request->validated(), ['stage_type_id' => $stageType]);
        $paginator = $this->service->drillDown($request->user(), 'overdue', $filters)
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
