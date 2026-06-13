<?php

namespace App\Modules\Analytics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Requests\DepartmentPerformanceRequest;
use App\Modules\Analytics\Resources\DepartmentPerformanceResource;
use App\Modules\Analytics\Resources\TaskListItemResource;
use App\Modules\Analytics\Resources\TeamMetricsResource;
use App\Modules\Analytics\Services\DepartmentDashboardService;
use App\Modules\Organization\Models\Department;
use App\Support\RateLimits;
use App\Traits\HasRateLimiting;

class DepartmentDashboardController extends Controller
{
    use HasRateLimiting;

    public function __construct(
        private DepartmentDashboardService $service,
    ) {}

    public function performance(DepartmentPerformanceRequest $request, Department $department): DepartmentPerformanceResource
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        return new DepartmentPerformanceResource(
            $this->service->performance($request->user(), $department, $request->validated())
        );
    }

    public function team(DepartmentPerformanceRequest $request, Department $department)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $items = $this->service->team($request->user(), $department);

        return TeamMetricsResource::collection($items);
    }

    public function drillDown(DepartmentPerformanceRequest $request, Department $department)
    {
        $this->checkRateLimit(RateLimits::LIST, [$request->user()->public_id]);

        $paginator = $this->service->drillDown($request->user(), $department, $request->validated())
            ->through(fn ($task) => new TaskListItemResource($task));

        return response()->json([
            'data' => $paginator->items(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }
}
